<?php

namespace Authifly\Storage;

use Authifly\Exception\RuntimeException;

/**
 * Persistent storage backed by a WordPress transient, scoped to the current user.
 *
 * Useful for data that must survive the OAuth redirect round trip (e.g. authorization state, PKCE code verifier)
 * when the main adapter storage is in-memory only.
 */
class WPTransientStorage implements StorageInterface
{
    /**
     * @var string
     */
    protected $transientName;

    /**
     * @var int
     */
    protected $expiration;

    /**
     * @param string $namespace
     * @param int $expiration in seconds
     * @throws RuntimeException
     */
    public function __construct($namespace = 'authifly', $expiration = 900)
    {
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : null;

        // Data is scoped per user; logged-out visitors would all share one bucket.
        if (empty($user_id)) {
            throw new RuntimeException('WPTransientStorage requires a logged-in user.');
        }

        $this->transientName = sprintf('%s_storage_%d', $namespace, $user_id);
        $this->expiration    = (int)$expiration;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        $key  = strtolower($key);
        $data = $this->all();

        return $data[$key] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value)
    {
        $data                   = $this->all();
        $data[strtolower($key)] = $value;

        $this->save($data);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $key  = strtolower($key);
        $data = $this->all();

        if (isset($data[$key])) {
            unset($data[$key]);
            $this->save($data);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMatch($key)
    {
        $key  = strtolower($key);
        $data = $this->all();

        foreach ($data as $k => $v) {
            if (strpos($k, $key) !== false) {
                unset($data[$k]);
            }
        }

        $this->save($data);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        delete_transient($this->transientName);
    }

    /**
     * @return array
     */
    protected function all()
    {
        $data = get_transient($this->transientName);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array $data
     */
    protected function save($data)
    {
        if (empty($data)) {
            delete_transient($this->transientName);

            return;
        }

        set_transient($this->transientName, $data, $this->expiration);
    }
}
