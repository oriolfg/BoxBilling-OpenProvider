<?php
/**
 * API documentation at https://doc.openprovider.eu/index.php/Main_Page
 */
class Registrar_Adapter_Openprovider extends Registrar_AdapterAbstract
{
    public $config = array(
        'username'    =>  null,
        'password'  =>  null,
        'hash'  =>  null,
        'nsGroup'  =>  'dns-openprovider'
        );
    public function __construct($options)
    {
        if (!extension_loaded('curl')) {
            throw new Registrar_Exception('CURL extension is not enabled');
        }
        if(isset($options['nsGroup']) && !empty($options['nsGroup'])) {
            $this->config['nsGroup'] = $options['nsGroup'];
        } else {
            throw new Registrar_Exception('Domain registrar "Openprovider" is not configured properly. Please update configuration parameter "Openprovider Name Servers Group name" at "Configuration -> Domain registration".');
        }
        if(isset($options['username']) && !empty($options['username'])) {
            $this->config['username'] = $options['username'];
        } else {
            throw new Registrar_Exception('Domain registrar "Openprovider" is not configured properly. Please update configuration parameter "Openprovider Username" at "Configuration -> Domain registration".');
        }
        if(isset($options['password']) && !empty($options['password'])) {
            $this->config['password'] = $options['password'];
        } elseif(!isset($options['hash']) || empty($options['hash'])) {
            throw new Registrar_Exception('Domain registrar "Openprovider" is not configured properly. Please update configuration parameter "Openprovider Password" at "Configuration -> Domain registration".');
        }
        if(isset($options['hash']) && !empty($options['hash'])) {
            $this->config['hash'] = $options['hash'];
        } elseif(!isset($options['password']) || empty($options['password'])) {
            var_dump($options['password']);
            throw new Registrar_Exception('Domain registrar "Openprovider" is not configured properly. Please update configuration parameter "Openprovider hash" at "Configuration -> Domain registration".');
        }
        unset($options['username']);
        unset($options['password']);
        unset($options['hash']);
        unset($options['nsGroup']);
    }
    public static function getConfig()
    {
        return array(
            'label'     =>  'Manages domains on Openprovider via API',
            'form'  => array(
                'nsGroup' => array('text', array(
                    'label' => 'Name Servers Group Name', 
                    'description'=>'If you leave this blank-dns used: dns-openprovider',
                    'required' => false
                    ),
                ),
                'username' => array('text', array(
                    'label' => 'Openprovider Username', 
                    'description'=>'Openprovider Username'
                    ),
                ),
                'password' => array('password', array(
                    'label' => 'Openprovider Pasword', 
                    'description'=>'(only required if no hash is provided)',
                    'renderPassword'    =>  true, 
                    'required' => false
                    ),
                ),
                'hash' => array('hash', array(
                    'label' => 'Openprovider hash', 
                    'description'=>'(only required if no password is provided)',
                    'required' => false
                    ),
                ),
                ),
            );
    }
    private function _getNsGroup()
    {
        if($this->isTestEnv()) {
            return 'dns-openprovider';
        }
        return $this->config['nsGroup'];
    }
    public function getTlds()
    {
        return array(
            '.cat', '.at', '.be', '.biz', '.ch', '.co.uk', '.co.za', 
            '.com', '.net', '.com.es', '.de', '.dk', '.es', '.eu', 
            '.fr', '.info', '.it', '.nl', '.nu', '.org', '.pt', 
            '.se', '.mobi', '.lu', '.li', '.la', '.pw', '.uk', 
            '.org.uk', '.me.uk', '.co.at', '.or.at', '.nom.es', 
            '.org.es', '.edu.es', '.re', '.yt', '.tf', '.wf', 
            '.pm', '.xyz', '.ru'
            );
    }
    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $args = array(
            'domains' => array(
                array( 'name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.'))
                )
            );
        $reply = $this->_makeRequest('checkDomainRequest', $args);

        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif ($reply[0]['status'] == 'free') {
            return true;
        } else {
            return false;
        }
    }
    public function isDomainCanBeTransfered(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Domain transfer checking is not implemented');
    }
    public function modifyNs(Registrar_Domain $domain)
    {
        $ns = array();
        $ns[] = array('name' => $domain->getNs1(), 'ip' => gethostbyname($domain->getNs1()));
        $ns[] = array('name' => $domain->getNs2(), 'ip' => gethostbyname($domain->getNs2()));
        if($domain->getNs3())  {
            $ns[] = array('name' => $domain->getNs3(), 'ip' => gethostbyname($domain->getNs3()));
        }
        if($domain->getNs4())  {
            $ns[] = array('name' => $domain->getNs4(), 'ip' => gethostbyname($domain->getNs4()));
        }
        $ns = array();

        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'nameServers' => $ns
            );
        $reply = $this->_makeRequest('modifyDomainRequest', $args);

        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif (isset($reply['id'])) {
            return true;
        } else {
            return false;
        }
    }
    public function modifyContact(Registrar_Domain $domain)
    {
        $existAccount = $this->_checkIfAcountExists($domain);
        if ($existAccount) {
            $c = $domain->getContactRegistrar();
            $args = array(
                'handle' => $existAccount,
                'phone' => array(
                    'countryCode' => '+'.$c->getTelCc(),
                    'areaCode' => substr($c->getTel(), 0, 2),
                    'subscriberNumber' => substr($c->getTel(), 2)
                    ),
                'email' => $c->getEmail(),
                'address' => array(
                    'street' => $c->getAddress1(),
                    'number' => '0',
                    'zipcode' => $c->getZip(),
                    'city' => $c->getCity(),
                    'state' => $c->getState(),
                    'country' => $c->getCountry(),
                    )
                );
            $reply = $this->_makeRequest('modifyCustomerRequest', $args);
            if (isset($reply['error'])) {
                throw new Registrar_Exception($reply['msg']);
            } else {
                return true;
            }
        } else {
            return false;
        }
    }
    public function transferDomain(Registrar_Domain $domain)
    {
        $userHandle = $this->_createCustomer($domain);
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'period' => $domain->getRegistrationPeriod(),
            'authCode' => $domain->getEpp(),
            'ownerHandle' => $userHandle,
            'adminHandle' => $userHandle,
            'techHandle' => $userHandle,
            'billingHandle' => $userHandle,
            'nsGroup' => $this->_getNsGroup(),
            'nameServers' => $ns
            );
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif ($reply['status'] == 'ACT') {
            return true;
        } else {
            return false;
        }
    }
    private function _retrieveDomain(Registrar_Domain $d)
    {
        $args = array(
            'domain' => array('name' => $d->getSld(), 'extension' => ltrim($d->getTld(), '.'))
            );
        $reply = $this->_makeRequest('retrieveDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return $reply;
        }
    }
    public function getDomainDetails(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.'))
            );
        $reply = $this->_makeRequest('retrieveDomainRequest', $args);
        $domain->setRegistrationTime(strtotime($reply['creationDate']));
        $domain->setExpirationTime(strtotime($reply['renewalDate']));
        $domain->setEpp($reply['authCode']);
        $domain->setPrivacyEnabled(($reply['isPrivateWhoisEnabled'] == 'true'));
        
        if(isset($reply['nameServers'][0])) {
            $domain->setNs1($reply['nameServers'][0]['name']);
        }
        if(isset($reply['nameServers'][0])) {
            $domain->setNs2($reply['nameServers'][0]['name']);
        }
        if(isset($reply['nameServers'][0])) {
            $domain->setNs3($reply['nameServers'][0]['name']);
        }
        if(isset($reply['nameServers'][0])) {
            $domain->setNs4($reply['nameServers'][0]['name']);
        }


        $owner = $this->_getHandleInfo($reply['ownerHandle']);
        $tel = $owner['phone']['areaCode'].$owner['phone']['subscriberNumber'];
        $telcc = $owner['phone']['countryCode'];
        $c = new Registrar_Domain_Contact();
        $c->setName($owner["name"]['fullName'])
        ->setFirstName($owner["name"]['firstName'])
        ->setLastName($owner["name"]['lastName'])
        ->setEmail($owner['email'])
        ->setCompany($owner['companyName'])
        ->setTel($tel)
        ->setTelCc($telcc)
        ->setAddress1($owner['address']['street'].' '.$owner['address']['number'])
        ->setCity($owner['address']['city'])
        ->setCountry($owner['address']['country'])
        ->setState($owner['address']['state'])
        ->setZip($owner['address']['zipcode'])
        ->setId($owner['id']);
        $domain->setContactRegistrar($c);
        return $domain;
    }
    public function _getHandleInfo($handle)
    { 
        $args = array(
            'handle' => $handle
            );
        $reply = $this->_makeRequest('retrieveCustomerRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['message'].' (Error: '.$reply['error'].')');
        } else {
            return $reply;
        }
    }
    public function deleteDomain(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array( 'name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.'))
            );
        $reply = $this->_makeRequest('deleteDomainRequest', $args);

        if (isset($reply['error'])) {
            $domainInfo = $this->_retrieveDomain($domain);
            if ($domainInfo["status"] == "DEL") {
                return true;
            } else {
                throw new Registrar_Exception($reply['msg']);
            }
        } else{
            return true;
        }
    }
    public function registerDomain(Registrar_Domain $domain)
    {
        $userHandle = $this->_createCustomer($domain);

        $ns = array();
        $ns[] = array('name' => $domain->getNs1(), 'ip' => gethostbyname($domain->getNs1()));
        $ns[] = array('name' => $domain->getNs2(), 'ip' => gethostbyname($domain->getNs2()));
        if($domain->getNs3())  {
            $ns[] = array('name' => $domain->getNs3(), 'ip' => gethostbyname($domain->getNs3()));
        }
        if($domain->getNs4())  {
            $ns[] = array('name' => $domain->getNs4(), 'ip' => gethostbyname($domain->getNs4()));
        }
        $ns = array();
        // $ns[] = array('name' => 'ns1.openprovider.nl', 'ip' => '93.180.69.5');
        // $ns[] = array('name' => 'ns2.openprovider.be', 'ip' => '144.76.197.172');

        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'period' => $domain->getRegistrationPeriod(),
            'ownerHandle' => $userHandle,
            'adminHandle' => $userHandle,
            'techHandle' => $userHandle,
            'billingHandle' => $userHandle,
            'nsGroup' => $this->_getNsGroup(),
            'nameServers' => $ns
            );
        $reply = $this->_makeRequest('createDomainRequest', $args);

        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif ($reply['status'] == 'ACT') {
            return true;
        } else {
            return false;
        }
    }
    public function renewDomain(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'period' => $domain->getRegistrationPeriod()
            );
        $reply = $this->_makeRequest('renewDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return true;
        }
    }
    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'isPrivateWhoisEnabled' => 1
            );
        $reply = $this->_makeRequest('modifyDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return true;
        }
    }
    
    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'isPrivateWhoisEnabled' => 0
            );
        $reply = $this->_makeRequest('modifyDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return true;
        }
    }
    public function getEpp(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.'))
            );
        $reply = $this->_makeRequest('requestAuthCodeDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif(isset($reply['authCode'])) {
            return $reply['authCode'];
        } else {
            throw new Registrar_Exception('Unknow error retriving Epp code');
        }
    }
    public function lock(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'isLocked' => 1
            );
        $reply = $this->_makeRequest('modifyDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return true;
        }
    }
    public function unlock(Registrar_Domain $domain)
    {
        $args = array(
            'domain' => array('name' => $domain->getSld(), 'extension' => ltrim($domain->getTld(), '.')),
            'isLocked' => 0
            );
        $reply = $this->_makeRequest('modifyDomainRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } else{
            return true;
        }
    }
    private function _createCustomer(Registrar_Domain $d)
    {
        //checking if user already exists
        $existAccount = $this->_checkIfAcountExists($d);
        if ($existAccount) {
            // l'usuari ja existeix
            return $existAccount;
        } else {       
            // es crea l'suuari nou
            $c = $d->getContactRegistrar();
            $args = array(
                'name' => array(
                    'initials' => substr($c->getFirstName(), 0, 1).'.'.substr($c->getLastName(), 0, 1).'.',
                    'firstName' => $c->getFirstName(),
                    'lastName' => $c->getLastName(),
                ),
                'gender' => 'M', 
                'address' => array(
                    'street' => $c->getAddress1(),
                    'number' => '0',
                    'zipcode' => $c->getZip(),
                    'city' => $c->getCity(),
                    'state' => $c->getState(),
                    'country' => $c->getCountry(),
                ),
                'phone' => array(
                    'countryCode' => '+'.$c->getTelCc(),
                    'areaCode' => substr($c->getTel(), 0, 2),
                    'subscriberNumber' => substr($c->getTel(), 2)
                ),
                'email' => $c->getEmail(),
            );
            var_dump($args);
            $reply = $this->_makeRequest('createCustomerRequest', $args);

            if (isset($reply['error'])) {
                throw new Registrar_Exception($reply['msg']);
            } elseif (isset($reply['handle'])) {
                return $reply['handle'];
            } else {
                throw new Registrar_Exception('Uknown error creating customer.');
            }
        }
    }
    private function _checkIfAcountExists(Registrar_Domain $d)
    {
        $c = $d->getContactRegistrar();

        $args = array(
            'emailPattern' => $c->getEmail()
            );
        $reply = $this->_makeRequest('searchCustomerRequest', $args);
        if (isset($reply['error'])) {
            throw new Registrar_Exception($reply['msg']);
        } elseif (isset($reply['results'][0]['handle'])) {
            return $reply['results'][0]['handle'];
        } else {
            return false;
        }
    }
    public function isTestEnv()
    {
        return $this->_testMode;
    }
    private function _getApiUrl()
    {
        if($this->isTestEnv()) {
            return 'https://api.cte.openprovider.eu';
        }
        return 'https://api.openprovider.eu';
    }
    private function includeAuthorizationParams()
    {
        if($this->isTestEnv()) {
            return array('username' => 'test', 'password' => 'test');
        } else {
            if($this->config['password'] && !empty($this->config['password'])) {
                return array('username' => $this->config['username'], 'password' => $this->config['password']);
            }
            if($this->config['hash'] && !empty($this->config['hash'])) {
                return array('username' => $this->config['username'], 'hash' => $this->config['hash']);
            } 
        }
    }
    private function _makeRequest($comand, $args = array())
    {
        $return = array();
        include_once 'OP_API.php';
        $api = new OP_API ($this->_getApiUrl());
        $request = new OP_Request;
        $request->setCommand($comand)->setAuth($this->includeAuthorizationParams())->setArgs($args);

        if ($this->isTestEnv()) {
            $result =  $api->setDebug(1)->process($request);
        } else {
            $result =  $api->process($request);
        }
        if ($result->getFaultCode() != 0) {
            $return['error']= $result->getFaultCode();
            $return['msg']= '';
            $return['comand']= $comand;
            if ($result->getFaultString()) {
                $return['msg'] .= $result->getFaultString();
            }
            if (!is_array($result->getValue())) {
                $return['msg'] .= ' - '.$result->getValue();
            }
        } else { 
            $return = $result->getValue();
        }
        return $return;
    }
}