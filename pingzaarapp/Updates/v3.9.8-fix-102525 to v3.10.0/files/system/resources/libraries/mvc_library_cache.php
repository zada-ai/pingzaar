<?php

class MVC_Library_Cache {

  /**
   * The path to the cache file folder
   *
   * @var string
   */
  private string $_cachepath = 'system/storage/cache/';

  /**
   * The name of the default cache file
   *
   * @var string
   */
  private string $_cachename = 'default';

  /**
   * The cache file extension
   *
   * @var string
   */
  private string $_extension = '.dat';

  /**
   * Default constructor
   *
   * @param string|array [optional] $config
   * @return void
   */
  public function __construct(string|array|null $config = null) {
    if ($config === null) {
      return;
    }

    if (is_string($config)) {
      $this->container($config);
    } elseif (is_array($config)) {
      $this->container($config['name']);
      $this->setCachePath($config['path']);
      $this->setExtension($config['extension']);
    }
  }

  /**
   * Load appointed cache
   * 
   * @return mixed
   */
  private function _loadCache(): array {
    $cacheDir = $this->getCacheDir();
    if (!file_exists($cacheDir)) {
      return [];
    }

    $content = file_get_contents($cacheDir);
    if ($content === false) {
      return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
  }

  /**
   * Get the filename hash
   * 
   * @return string
   */
  private function _getHash(string $filename): string {
    return hash('xxh3', $filename); // Using XXH3 for better performance
  }

  /**
   * Check whether a timestamp is still in the duration 
   * 
   * @param integer $timestamp
   * @param integer $expiration
   * @return boolean
   */
  private function _checkExpired(int $timestamp, int $expiration): bool {
    if ($expiration === 0) {
      return false;
    }
    return (time() - $timestamp) > $expiration;
  }

  /**
   * Check if a writable cache directory exists and if not create a new one
   * 
   * @return boolean
   */
  private function _checkCacheDir(): bool {
    $path = $this->getCachePath();
    
    if (!is_dir($path)) {
      if (!mkdir($path, 0775, true)) {
        throw new RuntimeException("Unable to create cache directory: $path");
      }
    }

    if (!is_readable($path) || !is_writable($path)) {
      if (!chmod($path, 0775)) {
        throw new RuntimeException("$path must be readable and writable");
      }
    }

    return true;
  }

  /**
   * Erase all expired entries
   * 
   * @return integer
   */
  private function deleteExpired(): int {
    $cacheData = $this->_loadCache();
    if (empty($cacheData)) {
      return 0;
    }

    $counter = 0;
    foreach ($cacheData as $key => $entry) {
      if ($this->_checkExpired($entry['time'], $entry['expire'])) {
        unset($cacheData[$key]);
        $counter++;
      }
    }

    if ($counter > 0) {
      file_put_contents($this->getCacheDir(), json_encode($cacheData));
    }

    return $counter;
  }

  /**
   * Get the cache directory path
   * 
   * @return string
   */
  public function getCacheDir(): string {
    if (!$this->_checkCacheDir()) {
      throw new RuntimeException('Cache directory check failed');
    }

    $filename = preg_replace('/[^0-9a-z._-]/i', '', strtolower($this->getCache()));
    return $this->getCachePath() . $this->_getHash($filename) . $this->getExtension();
  }

  /**
   * Cache path Setter
   * 
   * @param string $path
   * @return object
   */
  public function setCachePath(string $path): self {
    $this->_cachepath = rtrim($path, '/') . '/';
    return $this;
  }

  /**
   * Cache path Getter
   * 
   * @return string
   */
  public function getCachePath(): string {
    return $this->_cachepath;
  }

  /**
   * Cache name Getter
   * 
   * @return void
   */
  public function getCache(): string {
    return $this->_cachename;
  }

  /**
   * Cache file extension Setter
   * 
   * @param string $ext
   * @return object
   */
  public function setExtension(string $ext): self {
    $this->_extension = '.' . ltrim($ext, '.');
    return $this;
  }

  /**
   * Cache file extension Getter
   * 
   * @return string
   */
  public function getExtension(): string {
    return $this->_extension;
  }

  /**
   * Cache name Setter
   * 
   * @param string $name
   * @return object
   */
  public function container(string $name, bool $deleteExpired = false): self {
    $this->_cachename = $name;

    if ($deleteExpired) {
      $this->deleteExpired();
    }

    return $this;
  }

  /**
   * Check whether data accociated with a key
   *
   * @param string $key
   * @return boolean
   */
  public function has(string $key): bool {
    $cache = $this->_loadCache();

    if (!isset($cache[$key]['data'])) {
      return false;
    }

    return !$this->_isExpired($cache[$key]);
  }

  /**
   * Check whether a loaded entry has outlived the expiration it was stored with
   *
   * Entries written with an expiration of 0 never expire. Anything else is
   * compared against the time it was stored. Both has() and get() consult this,
   * so a key with a TTL stops being visible the moment it lapses rather than
   * lingering until eraseExpired() happens to be called.
   *
   * @param array $entry
   * @return boolean
   */
  private function _isExpired(array $entry): bool {
    if (!isset($entry['time'], $entry['expire'])) {
      return false;
    }

    return $this->_checkExpired((int) $entry['time'], (int) $entry['expire']);
  }

  /**
   * Check if container is empty
   *
   * @param string $key
   * @return boolean
   */
  public function empty(): bool {
    return empty($this->_loadCache());
  }

  /**
   * Check if container exist
   * 
   * @return object
   */
  public function exist(): bool {
    return file_exists($this->getCacheDir());
  }

  /**
   * Store data in the cache
   *
   * @param string $key
   * @param mixed $data
   * @param integer [optional] $expiration
   * @return object
   */
  public function set(string $key, mixed $data, int $expiration = 0): bool {
    $storeData = [
      'time' => time(),
      'expire' => $expiration,
      'data' => serialize($data)
    ];

    $dataArray = $this->_loadCache();
    $dataArray[$key] = $storeData;

    return file_put_contents($this->getCacheDir(), json_encode($dataArray)) !== false;
  }

  /**
   * Store raw data in the cache
   *
   * @param string $raw
   * @return bool
   */
  public function setRaw(string $raw): bool {
    $dataArray = $this->_loadCache();
    $dataArray['raw'] = $raw;
    return file_put_contents($this->getCacheDir(), json_encode($dataArray)) !== false;
  }


  /**
   * Store data in the cache as array
   *
   * @param string $key
   * @param mixed $data
   * @param integer [optional] $expiration
   * @return object
   */
  public function setArray(array $array, int $expiration = 0): bool {
    $dataArray = $this->_loadCache();
    $time = time();

    foreach ($array as $key => $value) {
      $dataArray[$key] = [
        'time' => $time,
        'expire' => $expiration,
        'data' => serialize($value)
      ];
    }

    return file_put_contents($this->getCacheDir(), json_encode($dataArray)) !== false;
  }

  /**
   * Retrieve cached data by its key
   * 
   * @param string $key
   * @param boolean [optional] $timestamp
   * @return string
   */
  public function get(string $key, bool $timestamp = false): mixed {
    $cachedData = $this->_loadCache();
    $type = $timestamp ? 'time' : 'data';

    if (!isset($cachedData[$key][$type])) {
      return null;
    }

    if ($this->_isExpired($cachedData[$key])) {
      return null;
    }

    return $timestamp ? $cachedData[$key][$type] : unserialize($cachedData[$key][$type]);
  }

  /**
   * Retrieve cached raw data
   * 
   * @param string $key
   * @param boolean [optional] $timestamp
   * @return string
   */
  public function getRaw(): ?string {
    $cachedData = $this->_loadCache();
    return $cachedData['raw'] ?? null;
  }

  /**
   * Retrieve all cached data
   * 
   * @param boolean [optional] $meta
   * @return array
   */
  public function getAll(bool $meta = false): array {
    if (!$meta) {
      $results = [];
      $cachedData = $this->_loadCache();
      
      foreach ($cachedData as $k => $v) {
        // Same expiry rule as has() and get(). No current caller stores a TTL
        // through setArray(), so this changes nothing today -- but leaving the
        // third reader out was how the original bug survived: any later code
        // pairing setArray($data, $ttl) with getAll() would silently serve
        // expired entries forever.
        if (isset($v['data']) && !$this->_isExpired($v)) {
          $results[$k] = unserialize($v['data']);
        }
      }
      
      return $results;
    }

    return $this->_loadCache();
  }

  /**
   * Erase cached entry by its key
   * 
   * @param string $key
   * @return object
   */
  public function delete(string $key): self {
    $cacheData = $this->_loadCache();
    
    if (isset($cacheData[$key])) {
      unset($cacheData[$key]);
      file_put_contents($this->getCacheDir(), json_encode($cacheData));
    }
    
    return $this;
  }

  /**
   * Erase all cached entries
   * 
   * @return object
   */
  public function clear(): self {
    $cacheDir = $this->getCacheDir();
    if (file_exists($cacheDir)) {
      @unlink($cacheDir);
    }
    return $this;
  }
}