<?php
/**
 * Credis, a Redis interface for the modest
 *
 * @author Justin Poliey <jdp34@njit.edu>
 * @copyright 2009 Justin Poliey <jdp34@njit.edu>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package Credis
 */

#require_once 'Credis/Client.php';

/**
 * A generalized Credis_Client interface for a cluster of Redis servers
 */
class Credis_Cluster
{
  /**
   * Collection of Credis_Client objects attached to Redis servers
   * @var Credis_Client[]
   */
  protected $clients;
  /**
   * If a server is set as master, all write commands go to that one
   * @var Credis_Client
   */
  protected $masterClient;
  /**
   * Aliases of Credis_Client objects attached to Redis servers, used to route commands to specific servers
   * @see Credis_Cluster::to
   * @var array
   */
  protected $aliases;
  
  /**
   * Hash ring of Redis server nodes
   * @var array
   */
  protected $ring;
  
  /**
   * Individual nodes of pointers to Redis servers on the hash ring
   * @var array
   */
  protected $nodes;
  
  /**
   * The commands that are not subject to hashing
   * @var array
   * @access protected
   */
  protected $dont_hash;

  /**
   * Creates an interface to a cluster of Redis servers
   * Each server should be in the format:
   *  array(
   *   'host' => hostname,
   *   'port' => port,
   *   'db' => db,
   *   'password' => password,
   *   'timeout' => timeout,
   *   'alias' => alias,
   *   'persistent' => persistent,
   *   'master' => master
   * )
   *
   * @param array $servers The Redis servers in the cluster.
   * @param int $replicas
   * @param bool $readOnMaster
   * @param bool $standAlone
   */
  public function __construct($servers, $replicas = 128, $readOnMaster = true, $standAlone = false)
  {
    $this->clients = array();
    $this->masterClient = null;
    $this->aliases = array();
    $this->ring = array();
    $clientNum = 0;
    foreach ($servers as $server)
    {
      $client = new Credis_Client(
        $server['host'],
        $server['port'],
        isset($server['timeout']) ? $server['timeout'] : 2.5,
        isset($server['persistent']) ? $server['persistent'] : '',
        isset($server['db']) ? $server['db'] : 0,
        isset($server['password']) ? $server['password'] : null
      );
      if($standAlone) {
        $client->forceStandalone();
      }
      if (isset($server['alias'])) {
        $this->aliases[$server['alias']] = $client;
      }
      if(isset($server['master']) && $server['master'] === true){
        $this->masterClient = $client;
        if(!$readOnMaster){
            continue;
        }
      }
      $this->clients[] = $client;
      for ($replica = 0; $replica <= $replicas; $replica++) {
          $md5num = hexdec(substr(md5($server['host'].':'.$server['port'].'-'.$replica),0,7));
          $this->ring[$md5num] = $clientNum;
      }
      $clientNum++;
    }
    ksort($this->ring, SORT_NUMERIC);
    $this->nodes = array_keys($this->ring);
    $this->dont_hash = array(
      'RANDOMKEY', 'DBSIZE', 'PIPELINE', 'EXEC',
      'SELECT',    'MOVE',    'FLUSHDB',  'FLUSHALL',
      'SAVE',      'BGSAVE',  'LASTSAVE', 'SHUTDOWN',
      'INFO',      'MONITOR', 'SLAVEOF'
    );
  }
  /**
   * Get a client by index or alias.
   *
   * @param string|int $alias
   * @throws CredisException
   * @return Credis_Client
   */
  public function client($alias)
  {
    if (is_int($alias) && isset($this->clients[$alias])) {
      return $this->clients[$alias];
    }
    else if (isset($this->aliases[$alias])) {
      return $this->aliases[$alias];
    }
    throw new CredisException("Client $alias does not exist.");
  }

  /**
   * Get an array of all clients
   *
   * @return array|Credis_Client[]
   */
  public function clients()
  {
    return $this->clients;
  }

  /**
   * Execute a command on all clients
   *
   * @return array
   */
  public function all()
  {
    $args = func_get_args();
    $name = array_shift($args);
    $results = array();
    foreach($this->clients as $client) {
      $results[] = $client->__call($name, $args);
    }
    return $results;
  }

  /**
   * Get the client that the key would hash to.
   *
   * @param string $key
   * @return \Credis_Client
   */
  public function byHash($key)
  {
    return $this->clients[$this->hash($key)];
  }

  /**
   * Execute a Redis command on the cluster with automatic consistent hashing and read/write splitting
   *
   * @param string $name
   * @param array $args
   * @return mixed
   */
  public function __call($name, $args)
  {
    if($this->masterClient instanceof Credis_Client && !Credis_Rwsplit::isReadOnlyCommand($name)){
        return $this->masterClient->__call($name, $args);
    }
    if (isset($this->dont_hash[strtoupper($name)]) || !isset($args[0])) {
      $client = $this->clients[0];
    }
    else {
      $client = $this->byHash($args[0]);
    }
    return $client->__call($name, $args);
  }

  /**
   * Get client index for a key by searching ring with binary search
   *
   * @param string $key The key to hash
   * @return int The index of the client object associated with the hash of the key
   */
  public function hash($key)
  {
    $needle = hexdec(substr(md5($key),0,7));
    $server = $min = 0;
    $max = count($this->nodes) - 1;
    while ($max >= $min) {
      $position = (int) (($min + $max) / 2);
      $server = $this->nodes[$position];
      if ($needle < $server) {
        $max = $position - 1;
      }
      else if ($needle > $server) {
        $min = $position + 1;
      }
      else {
        break;
      }
    }
    return $this->ring[$server];
  }
}

