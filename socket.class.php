<?php
/**
 * Socket class of phpWebSockets
 *
 * @author Bruno Freitas <brufreitas@gmail.com> based on Moritz Wutz <moritzwutz@gmail.com>
 * @version 0.1
 */
/**
 * This is the main socket class
 */
class socket
{
  /**
   *
   * @master socket Holds the master socket
   */
  private $master;
  /**
   *
   * @allsockets array Holds all connected sockets
   */
  private $allsockets = array();
  private $readBufferSize = 2048;
  private $nonblockingAcceptedErrors = array (/*EAGAIN or EWOULDBLOCK*/11, /*EINPROGRESS*/115);

  private $consoleType = "bash";
  private $loopId = 0;

  // Events
  private $on_clientConnect = array();          // $socket_index
  private $on_clientDisconnect = array();       // $socket_index
  private $on_messageReceived = array();        // $socket_index, rawData
  private $on_messageSent = array();            // $socket_index, rawData
  private $on_tick = array();                   // $loopId

  /**
   * Create a socket on given host/port
   * @param string $host The host/bind address to use
   * @param int $port The actual port to bind on
   */
  private function createSocket($host, $port) {
    if (!$this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
      die("socket_create() failed, reason: ".socket_strerror($this->master));
    }
    self::console("Socket [{$this->master}] created.");
    socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
    if (!@socket_bind($this->master, $host, $port)) {
      self::console("socket_bind() failed, reason: [".socket_strerror(socket_last_error($this->master))."]", "System", "white", "red");
      exit;
    }
    self::console("Socket bound to [{$host}:{$port}].");
    if ( ($ret = socket_listen($this->master,5)) < 0 ) {
      die("socket_listen() failed, reason: ".socket_strerror($ret));
    }
    self::console("Start listening on Socket.");
    $this->allsockets[] = $this->master;
  }

  public function on($event, $function) {
    $this->console(__FUNCTION__."/Binding event: " . $event, "green");
    if (strtoupper($event) == "CLIENTCONNECT") {
      $this->on_clientConnect[] = $function;
    } elseif (strtoupper($event) == "CLIENTDISCONNECT") {
      $this->on_clientDisconnect[] = $function;
    } elseif (strtoupper($event) == "MESSAGERECEIVED") {
      $this->on_messageReceived[] = $function;
    } elseif (strtoupper($event) == "MESSAGESENT") {
      $this->on_messageSent[] = $function;
    } elseif (strtoupper($event) == "TICK") {
      $this->on_tick[] = $function;
    } else {
      $this->console("socket->on(): Unknown event: `{$event}`", "white", "red");
    }
  }

  public function listen($host, $port) {
    $this->createSocket($host, $port);
    $this->run();
  }

  private function run() {
    while (true) {
      $this->loopId++;

      $changed_sockets = $this->allsockets;
      $write  = array();
      $except = array();
      //blocks execution until data is received from any socket
      //OR will wait 1ms(1000us) - should theoretically put less pressure on the cpu
      $num_sockets = socket_select($changed_sockets, $write, $except, 0, 1000);
      foreach ($changed_sockets as $socket) {
        // master socket changed means there is a new socket request
        if ($socket == $this->master) {
          // if accepting new socket fails
          if (($client = socket_accept($this->master)) < 0) {
            $this->console("socket_accept() failed: reason: " . socket_strerror(socket_last_error($client)), "white", "red");
            continue;
          }
          // if it is successful push the client to the allsockets array
          $this->allsockets[] = $client;
          end($this->allsockets);
          $new_socket_index = key($this->allsockets);
          // $this->console("Socket++ [{$new_socket_index}]", "light_green");
          foreach($this->on_clientConnect as $func) {
            call_user_func($func, $new_socket_index);
          }
          continue;
        }

        $rawData = "";
        while (($bytes = @socket_recv($socket, $buffer, $this->readBufferSize, MSG_DONTWAIT)) !== false) {
          $rawData .= $buffer;
          // if ($bytes < $this->readBufferSize) $this->console("Read {$bytes} bytes, will break.");;
          // if ($bytes < $this->readBufferSize) break;
          // $this->console("Read {$bytes} bytes");
          usleep(500);
        }

        // $this->console("Stop reading: ".socket_last_error($socket)."-".socket_strerror(socket_last_error($socket)));

        if ($bytes === false && !in_array(socket_last_error($socket), $this->nonblockingAcceptedErrors)) {
          $this->console("socket_recv() failed, reason: [".socket_strerror(socket_last_error($socket))."]", "white", "red");
          continue;
        }

        $socket_index = array_search($socket, $this->allsockets);
        // $this->console("Received: [{$bytes}] bytes from socket_index: [{$socket_index}]");
        //  the client socket changed and there is no data --> disconnect
        if ($bytes === 0) {
          // $this->console("no data");
          $this->disconnect($socket);
          continue;
        }

        foreach($this->on_messageReceived as $func) {
          call_user_func($func, $socket_index, $rawData);
        }
      }  //foreach socket_changed

      foreach($this->on_tick as $func) {
        call_user_func($func, $this->loopId);
      }

    } //while true
  }

  private function getSocketIndexByResource ($socket) {
    return array_search($socket, $this->allsockets);
  }

  protected function disconnect ($socket) {
    if (is_resource($socket)){
      $socket_index = $this->getSocketIndexByResource($socket);
    } else {
      $socket_index = $socket;
      $socket = $this->allsockets[$socket_index];
    }

    if ($socket_index >= 0) {
      unset($this->allsockets[$socket_index]);
    }
    socket_close($socket);

    foreach($this->on_clientDisconnect as $func) {
      call_user_func($func, $socket_index);
    }

    // $this->console("Socket-- [{$socket_index}]", "light_red");
  }

  /**
   * Log a message
   * @param string $msg The message
   * @param string $type The type of the message
   */
  protected function console($msg, $fg = null, $bg = null) {
    if ($consoleType = "bash") {
      $fgCode = bash_fgColorCode($fg);
      $bgCode = bash_bgColorCode($bg);
      $msg = 
        "\033[{$fgCode}m".
        "\033[{$bgCode}m".
        $msg.
        "\033[0m";
    }
    print date("Y-m-d H:i:s").": {$msg}\n";
    // $msg = explode("\n", $msg);
    // foreach( $msg as $line ) {
    // print date('Y-m-d H:i:s') . " {$type}: {$msg}\n";
    // }
  }

  /**
   * Send a message over the socket
   * @param socket $client The destination socket
   * @param string $msg The message
   */
  private function send($client_socket, $msg) {
    socket_write($client_socket, $msg, strlen($msg));

    foreach($this->on_messageSent as $func) {
      $socket_index = $this->getSocketIndexByResource($client_socket);
      call_user_func($func, $socket_index, $msg);
    }

  }

  /**
   * Send a message thru the socket (using the control index of the socket)
   * @param int $client_index The index destination socket
   * @param string $msg The message
   */
  protected function sendByIndex($client_index, $msg) {
    if (!isset($this->allsockets[$client_index])) {
      $this->console("sendByIndex Impossible Situation: Index `{$client_index}` unrecognized", "white", "red");
    }

    $client_socket = $this->allsockets[$client_index];
    $this->send($client_socket, $msg);
  }

  /**
   * Send a message thru the socket to all the other client sockets but $socket_index_me
   * @param int $socket_index_me The index destination socket
   * @param string $msg The message that will be send
   */
  protected function sendToOthers($socket_index_me, $msg) {
    $skipSockets = array($this->master, $this->allsockets[$socket_index_me]);
    $them = array_diff($this->allsockets, $skipSockets);

    foreach ($them as $sock) {
      $this->send($sock, $msg);
    }
  }

  /**
   * Send a message thru the socket to all client sockets (broadcast)
   * @param string $msg The message that will be send
   */
  protected function sendToAll($msg) {
    $clients = array_diff($this->allsockets, array($this->master));

    foreach ($clients as $sock) {
      $this->send($sock, $msg);
    }
  }
}

function bash_fgColorCode($colorName) {
  if     (!$colorName                 ) {return "0;37";}
  elseif ($colorName == "dark_gray"   ) {return "1;30";}
  elseif ($colorName == "blue"        ) {return "0;34";}
  elseif ($colorName == "light_blue"  ) {return "1;34";}
  elseif ($colorName == "green"       ) {return "0;32";}
  elseif ($colorName == "light_green" ) {return "1;32";}
  elseif ($colorName == "cyan"        ) {return "0;36";}
  elseif ($colorName == "light_cyan"  ) {return "1;36";}
  elseif ($colorName == "red"         ) {return "0;31";}
  elseif ($colorName == "light_red"   ) {return "1;31";}
  elseif ($colorName == "purple"      ) {return "0;35";}
  elseif ($colorName == "light_purple") {return "1;35";}
  elseif ($colorName == "brown"       ) {return "0;33";}
  elseif ($colorName == "yellow"      ) {return "1;33";}
  elseif ($colorName == "light_gray"  ) {return "0;37";}
  elseif ($colorName == "black"       ) {return "0;30";}
  elseif ($colorName == "white"       ) {return "1;37";}
  else                                  {return "0;37";}
}

function bash_bgColorCode($colorName) {
  if     (!$colorName                 ) {return "40";}
  elseif ($colorName == "black"       ) {return "40";}
  elseif ($colorName == "red"         ) {return "41";}
  elseif ($colorName == "green"       ) {return "42";}
  elseif ($colorName == "yellow"      ) {return "43";}
  elseif ($colorName == "blue"        ) {return "44";}
  elseif ($colorName == "magenta"     ) {return "45";}
  elseif ($colorName == "cyan"        ) {return "46";}
  elseif ($colorName == "light_gray"  ) {return "47";}
  else                                  {return "40";}
}
?>