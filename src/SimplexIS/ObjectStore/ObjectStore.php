<?php

namespace SimplexIS\ObjectStore;

class ObjectStore
{

    /**
     *
     * @var \OpenCloud\OpenStack
     */
    private $connection;

    /**
     *
     * @var array OpenCloud\ObjectStore\Service
     */
    private $service;

    /**
     *
     * @return \OpenCloud\OpenStack
     */
    public function getConnection()
    {
        if (! isset($this->connection)) {
            $this->connection = new \OpenCloud\OpenStack($this->getConfig('openstack_identity_url'), [
                'username' => $this->getConfig('username'),
                'password' => $this->getConfig('password'),
                'tenantName' => $this->getConfig('tenant')
            ]);
            
            if ($this->getConfig('cache_credentials')) {
                if (\Cache::has('object-store-credentials')) {
                    $this->connection->importCredentials(unserialize(\Cache::get('object-store-credentials')));
                }
                
                $token = $this->connection->getTokenObject();
                if (! $token || $token->hasExpired()) {
                    $this->connection->authenticate();
                    \Cache::put('object-store-credentials', serialize($this->connection->exportCredentials()), 0);
                }
            }
        }
        
        return $this->connection;
    }

    /**
     *
     * @param string $urltype            
     * @return \OpenCloud\ObjectStore\Service
     */
    public function getService($urltype = null)
    {
        if (! $urltype) {
            $urltype = $this->getConfig('default_url_type');
        }
        
        if (! isset($this->service[$urltype])) {
            $this->service[$urltype] = $this->getConnection()->objectStoreService('swift', 'NL', $urltype);
        }
        
        return $this->service[$urltype];
    }

    /**
     *
     * @param string $container            
     * @param string $urltype            
     * @return OpenCloud\ObjectStore\Resource\Container
     */
    public function getContainer($container = null, $urltype = null)
    {
        if (! $container) {
            $container = $this->getConfig('default_container');
        }
        
        return $this->getService($urltype)->getContainer($this->getEnvironmentString() . $container);
    }

    /**
     *
     * @param string $name            
     * @param string $container            
     * @param string $urltype            
     * @return OpenCloud\ObjectStore\Resource\DataObject
     */
    public function getObject($name, $container = null, $urltype = null)
    {
        return $this->getContainer($container, $urltype)->getObject($name);
    }

    /**
     *
     * @param string $name            
     * @param string $container            
     * @param string $urltype            
     * @return OpenCloud\ObjectStore\Resource\DataObject
     */
    public function getPartialObject($name, $container = null, $urltype = null)
    {
        return $this->getContainer($container, $urltype)->getPartialObject($name);
    }

    /**
     *
     * @param string $name            
     * @param string $container            
     * @return boolean
     */
    public function objectExists($name, $container = null)
    {
        return $this->getContainer($container)->objectExists($name);
    }

    /**
     *
     * @param string $container            
     * @param boolean $ssl            
     * @return string
     */
    public function getContainerURL($container = null, $ssl = true)
    {
        if (! $container) {
            $container = $this->getConfig('default_container');
        }
        
        $suffix = $this->getEnvironmentString() . $container . '/';
        
        if ($ssl) {
            return $this->getConfig('ssl_base_url') . $suffix;
        } else {
            return $this->getConfig('base_url') . $suffix;
        }
    }

    /**
     *
     * @param string $object            
     * @param string $container            
     * @param string $filename            
     * @param boolean $ssl            
     * @param number $expires            
     * @return string
     */
    public function getTempURL($object, $container = null, $filename = null, $ssl = true, $expires = 10)
    {
        $expires = time() + $expires;
        
        $hash = hash_hmac('sha1', "GET\n" . $expires . "\n/" . $this->getEnvironmentString() . $container . "/" . rawurlencode($object), $this->getConfig('temp_url_secret'));
        
        return $this->getContainerURL($container, $ssl) . rawurlencode($object) . '?temp_url_sig=' . $hash . '&temp_url_expires=' . $expires . ($filename ? '&filename=' . urlencode($filename) : '');
    }

    /**
     * Syntactic sugar
     *
     * @param string $key            
     */
    public function getConfig($key)
    {
        return \Config::get('object-store::' . $key);
    }

    /**
     * Returns container prefix depending on environment
     *
     * @return string
     */
    public function getEnvironmentString()
    {
        return \App::environment() && ! \App::environment('production') ? (\App::environment() . '-') : '';
    }
}