<?php

class synoapi
{

  private $lastError;
  private $lastErrorCode;
  private $username;
  private $password;
  private $url;
  private $curl;
  private $response;
  private $loggedin;
  private $services;

  function __construct($url, $username, $password)
  {
    $this->setError(0, '');
    $this->username = $username;
    $this->password = $password;
    $this->url = $url;

    if (!function_exists('curl_init'))
      throw new Exception('php cURL extension must be installed and enabled');

    $this->curl = curl_init();
    curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 0); // must not verify host if not valid yet
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, 0); // must not verify peer (same reason)
    curl_setopt($this->curl, CURLOPT_COOKIEFILE, ''); // Activate cookie engine

    if(!$this->info()){
      $this->loggedin = false;
      $this->internalError('API info not found');
      return;
    } else
      $this->services = $this->response->data;

    $this->loggedin = $this->login();
  }

  function __destruct()
  {
    $this->logout();
    curl_close($this->curl);
  }

  private function setError($code, $msg)
  {
    $this->lastErrorCode = $code;
    $this->lastError = $msg;
  }

  private function internalError($msg)
  {
    $this->setError(-1, $msg);
  }

  public function getLastError()
  {
    return $this->lastError;
  }

  public function getLastErrorCode()
  {
    return $this->lastErrorCode;
  }

  private function success()
  {
    $this->setError(0, '');

    if (!$this->response) {
      $this->internalError('Unknown error : no data');
      return false;
    }

    if ($this->response->success)
      return true;

    if(isset($this->response->error->code)) {
      $this->setError($this->response->error->code, 'SYNO Error code = ' . $this->response->error->code);
    } else
      $this->internalError('Unknown error : invalid data');

    return false;
  }

  public function connected()
  {
    return $this->loggedin;
  }

  public function directRequest($path)
  {
    curl_setopt($this->curl, CURLOPT_URL, $this->url . $path);
    $this->response = json_decode(curl_exec($this->curl));
    return $this->success();
  }

  public function request($service, $version, $method, $params = '')
  {
    if(!isset($this->services->$service->path)){
      $this->internalError('Service does not exist : ' . $service);
      return false;
    }

    $path = '/webapi/' . $this->services->$service->path . '?api=' . $service . '&version=' . $version . '&method=' . $method . $params;
    return $this->directRequest($path);
  }

  public function info()
  {
    return $this->directRequest('/webapi/query.cgi?api=SYNO.API.Info&version=1&method=query');
  }

  public function login()
  {
    return $this->request('SYNO.API.Auth', 3, 'login', '&account=' . $this->username . '&passwd=' . $this->password);
  }

  public function logout()
  {
    return $this->request('SYNO.API.Auth', 3, 'logout');
  }

  public function dumpResponse()
  {
    var_dump($this->response);
  }

  public function Network_Info($ifname)
  {
    if($this->request('SYNO.Core.Network.LocalBridge', 1, 'get', "&ifname=$ifname"))
      return $this->response;
    else
      return false;
  }

  public function NSM_Devices()
  {
    if($this->request('SYNO.Core.Network.NSM.Device', 4, 'get', '&conntype="all"'))
      return $this->response;
    else
      return false;
  }

  public function searchCertificates()
  {
    return $this->request('SYNO.Core.Certificate.CRT', 1, 'list');
  }

  public function updateCertificate($certname, $key, $cert, $chain)
  {
    $id = '';
    $desc = '';
    $default = 'false';

    if($this->searchCertificates()) {

      if(!isset($this->response->data->certificates))
        return false;

      foreach($this->response->data->certificates as $crt)
        if($crt->subject->common_name == $certname) {
          $id = $crt->id;
          $desc = $crt->desc;
          if($crt->is_default == '1')
            $default = 'true';
          break;
        }
    }

    $post = new multipart_data();
    $post->addFile('key', $key);
    $post->addFile('cert', $cert);
    $post->addFile('inter_cert', $chain);
    $post->addPart('id', $id);
    $post->addPart('desc', $desc);
    $post->addPart('as_default', $default);
    $data = $post->postdata();

    curl_setopt($this->curl, CURLOPT_HTTPHEADER, [ 'Content-Type: multipart/form-data; boundary=' . $post->boundary(), "Content-Length: " . strlen($data) ]);
    curl_setopt($this->curl, CURLOPT_POST, 1);
    curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($this->curl, CURLOPT_URL, $this->url . "/webapi/entry.cgi?api=SYNO.Core.Certificate&method=import&version=1");
    $this->response = json_decode(curl_exec($this->curl));
    return $this->success();
  }

}

class multipart_data
{
  private $_postdata;
  private $_boundary;

  function __construct()
  {
    $this->_postdata = '';
    $this->_boundary = '---------------------' . md5(mt_rand() . microtime());
  }

  public function postdata()
  {
    return $this->_postdata . "--$this->_boundary--\r\n";
  }

  public function boundary()
  {
    return $this->_boundary;
  }

  public function addPart($name, $data, $filename = null)
  {
    if (isset($filename))
      $filename = "; filename=\"$filename\"";
    else
      $filename = '';

    $this->_postdata .= "--$this->_boundary\r\n" .
                        "Content-Disposition: form-data; name=\"$name\"$filename\r\n" .
                        "Content-Type: application/x-x509-ca-cert\r\n\r\n" .
                        "$data\r\n";
  }

  public function addFile($name, $filename)
  {
    $data = file_get_contents($filename);
    $this->addPart($name, $data, $filename);
  }

}
