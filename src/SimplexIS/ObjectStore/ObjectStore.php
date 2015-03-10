<?php

namespace SimplexIS\ObjectStore;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Exception\ClientErrorResponseException;

class ObjectStore
{
    /**
     *
     * @return \OpenCloud\OpenStack
     */
    public function getConnection()
    {
        $res =  new \OpenCloud\OpenStack($this->getConfig('openstack_identity_url'), [
            'username' => $this->getConfig('username'),
            'password' => $this->getConfig('password'),
            'tenantName' => $this->getConfig('tenant')
        ]);
        
        //Get credentials from cache if needed
        if ($this->getConfig('cache_credentials')) {
            if (\Cache::has('object-store-credentials')) {
                $res->importCredentials(unserialize(\Cache::get('object-store-credentials')));
            }
            
            $token = $res->getTokenObject();
            if (! $token || $token->hasExpired()) {
                $res->authenticate();
                Log::info('re-authenticated');
                \Cache::put('object-store-credentials', serialize($res->exportCredentials()), 0);
            }
        }else{
            $res->authenticate();
        }
        
        return $res;
    }

    /**
     *
     * @param string|null $urltype            
     * @return \OpenCloud\ObjectStore\Service
     */
    public function getService($urltype = null)
    {
        if (! $urltype) {
            $urltype = $this->getConfig('default_url_type');
        }
        
        return $this->getConnection()->objectStoreService('swift', 'NL', $urltype);
    }

    /**
     *
     * @param string $name            
     * @param string|null $container            
     * @param string|null $urltype            
     * @return OpenCloud\ObjectStore\Resource\DataObject
     */
    public function getObject($name, $container = null, $urltype = null, $headers = [])
    {
        return $this
            ->getConnection()
            ->get(
                $this->getObjectUrl($name, $container, $urltype), 
                $headers
                )
            ->send()->getBody();
    }
    
    /**
     * 
     * @param string|null $urltype
     * @return Guzzle\Http\Url
     */
    public function getServiceUrl($urltype = null)
    {
        return $this->getService($urltype)->getUrl();
    }
    
    /**
     * 
     * @param string $name
     * @param string|null $container
     * @param string|null $urltype
     * @return Guzzle\Http\Url
     */
    public function getObjectUrl($name, $container = null, $urltype = null)
    {
        if (! $container) {
            $container = $this->getConfig('default_container');
        }
        
        return $this
            ->getServiceUrl($urltype)
            ->addPath($this->getEnvironmentString().$container)
            ->addPath($name);
    }
    
    /**
     * 
     * @param string $name
     * @param mixed $data
     * @param string|null $container
     * @param string|null $urltype
     * @param array $headers
     */
    public function uploadObject($name, $data, $container = null, $urltype = null, $headers = [])
    {
        return $this
            ->getConnection()
            ->put(
                $this->getObjectUrl($name, $container, $urltype), 
                $headers, 
                EntityBody::factory($data)
                )
            ->send();
    }
    
    /**
     * 
     * @param string $name
     * @param string|null $container
     * @param string|null $urltype
     * @throws ClientErrorResponseException
     * @return boolean
     */
    public function deleteObject($name, $container = null, $urltype = null)
    {
        try {
            $this
            ->getConnection()
            ->delete(
                $this->getObjectUrl($name, $container, $urltype)
                )
            ->send()->getBody();
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            } else {
                throw $e;
            }
        }
        
        return true;
    }

    /**
     *
     * @param string $name            
     * @param string|null $container            
     * @return boolean
     */
    public function objectExists($name, $container = null, $urltype = null)
    {
        try {
            $this
            ->getConnection()
            ->head(
                $this->getObjectUrl($name, $container, $urltype)
                )
            ->send()->getBody();
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            } else {
                throw $e;
            }
        }
        
        return true;
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
        if (! $container) {
            $container = $this->getConfig('default_container');
        }
        
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
