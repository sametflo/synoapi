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
  private $multiReq;

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

  public function serviceExists($service)
  {
    return isset($this->services->$service->path);
  }

  public function directRequest($path)
  {
    curl_setopt($this->curl, CURLOPT_URL, $this->url . $path);
    $this->response = json_decode(curl_exec($this->curl));
    return $this->success();
  }

  public function multiReqInit()
  {
    $this->multiReq = [];
  }

  public function multiReqPrepare($service, $version, $method, $params = [])
  {
    if(!$this->serviceExists($service)){
      $this->internalError("Service does not exist : $service");
      return false;
    }

    $this->multiReq[] = array_merge(['api' => $service,'method' => $method, 'version' => $version], $params);
    return true;
  }

  public function multiReqExec($stop_on_error = false)
  {
    if($stop_on_error)
      $stop_on_error = 'true';
    else
      $stop_on_error = 'false';
    $this->request('SYNO.Entry.Request', 1, 'request', ['stop_when_error' => $stop_on_error, 'mode' => 'sequential', 'compound' => json_encode($this->multiReq)]);
    $this->multiReqInit();
  }

  public function request($service, $version, $method, $params = [])
  {
    if(!$this->serviceExists($service)){
      $this->internalError("Service does not exist : $service");
      return false;
    }

    $path = '/webapi/' . $this->services->$service->path . '?api=' . $service . '&version=' . $version . '&method=' . $method;
    foreach($params as $param => $value)
      $path .= '&' . $param . '=' . urlencode($value);
    return $this->directRequest($path);
  }

  public function info()
  {
    return $this->directRequest('/webapi/query.cgi?api=SYNO.API.Info&version=1&method=query');
  }

  public function login()
  {
    return $this->request('SYNO.API.Auth', 3, 'login', ['account' => $this->username, 'passwd' => $this->password]);
  }

  public function logout()
  {
    return $this->request('SYNO.API.Auth', 3, 'logout');
  }

  public function dumpResponse()
  {
    var_dump($this->response);
  }

  public function Backup_Tasks($additionals = false)
  {
    if($additionals)
      $params = ['additional' => '["' . implode('","', $additionals) . '"]'];
    else
      $params = [];
    if($this->request('SYNO.Backup.Task', '1', 'list', $params))
      return $this->response;
    else
      return false;
  }

  private function Backup_Task_Req($method, $taskId, $additionals = false)
  {
    $params = ['task_id' => $taskId];
    if($additionals)
      $params['additional'] = '["' . implode('","', $additionals) . '"]';
    if($this->request('SYNO.Backup.Task', '1', $method, $params))
      return $this->response;
    else
      return false;
  }

  public function Backup_Task_Data($taskId, $additionals = false)
  {
    return $this->Backup_Task_Req('get', $taskId, $additionals);
  }

  public function Backup_Task_Status($taskId, $additionals = false)
  {
    return $this->Backup_Task_Req('status', $taskId, $additionals);
  }

  public function Region_Info()
  {
    if($this->request('SYNO.Core.Region.NTP', 1, 'get'))
      return $this->response;
    else
      return false;

  }

  public function Firewall_Info()
  {
    $this->request('SYNO.Core.Security.Firewall', 1, 'get');
    return $this->response;
  }

  public function Firewall_Profile_Info($nom)
  {
    $this->request('SYNO.Core.Security.Firewall.Profile', 1, 'get', ['name' => $nom]);
    return $this->response;
  }

  public function Apply_Firewall($nom,$profile = false)
  {
    $this->request('SYNO.Core.Security.Firewall.Profile.Apply', 1, 'start', ['name' => $nom, 'profile_applying' => $profile?'true':'false']);
    $taskid = $this->response->data->task_id;
    $done = false;
    while(!$done){
      $this->request('SYNO.Core.Security.Firewall.Profile.Apply', 1, 'status', ['task_id' => $taskid]);
      if($this->response->data->finish)
        $done = true;
    }
    $this->request('SYNO.Core.Security.Firewall.Profile.Apply', 1, 'stop');
  }

  public function Health_Info(){
    if(!$this->request('SYNO.Storage.CGI.Storage', 1, 'load_info'))
      return false;

    $diskStats = [];
    $checkup = $this->response;
    foreach($checkup->data->disks as $disk){
      $this->request('SYNO.Storage.CGI.Smart', 1, 'get_health_info', ['device' => $disk->device]);
      $diskStats[$disk->device] = $this->response;
    }
    return $diskStats;
  }

  public function Storage_Info()
  {
    if($this->request('SYNO.Storage.CGI.Storage', '1', 'load_info'))
      return $this->response;
    else
      return false;
  }

  public function System_Info()
  {
    if($this->request('SYNO.Core.System', '1', 'info'))
      return $this->response;
    else
      return false;
  }

  public function System_Status()
  {
    if($this->request('SYNO.Core.System.Status', '1', 'get'))
      return $this->response;
    else
      return false;
  }

  public function Network_Info($ifname)
  {
    if($this->request('SYNO.Core.Network.LocalBridge', 1, 'get', ['ifname' => $ifname]))
      return $this->response;
    else
      return false;
  }

  public function NSM_Devices()
  {
    if($this->request('SYNO.Core.Network.NSM.Device', 4, 'get', ['conntype' => '"all"']))
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

    curl_setopt($this->curl, CURLOPT_HTTPHEADER, [ 'Content-Type: multipart/form-data; boundary=' . $post->boundary(), 'Content-Length: ' . strlen($data) ]);
    curl_setopt($this->curl, CURLOPT_POST, 1);
    curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($this->curl, CURLOPT_URL, $this->url . '/webapi/entry.cgi?api=SYNO.Core.Certificate&method=import&version=1');
    $this->response = json_decode(curl_exec($this->curl));
    return $this->success();
  }

  function checkProgressUpdate()
  {
    $this->request('SYNO.Core.Upgrade', 1, 'status');
    return $this->response;
  }

  function checkProgressDownloadUpdate()
  {
    $this->request('SYNO.Core.Upgrade.Server.Download', 2, 'progress', ['need_download_target' => 'true']);
    return $this->response;
  }

  function checkUpdate()
  {
    $this->request('SYNO.Core.Upgrade.Server', 2, 'check', ['user_reading' => 'true', 'need_auto_smallupdate' => 'true', 'need_promotion' => 'true']);
    return $this->response;
  }

  function getServices()
  {
    return $this->services;
  }

  function getResponse()
  {
    return $this->response;
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
