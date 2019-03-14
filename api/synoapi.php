<?php

class synoapi
{

  private $username;
  private $password;
  private $url;
  private $curl;
  private $cookiefile;
  private $response;
  private $token;

  function __construct($url, $username, $password)
  {
    $this->username = $username;
    $this->password = $password;
    $this->url = $url;
    $this->cookiefile = tempnam(sys_get_temp_dir(), 'cookie');
	
    if (!function_exists('curl_init'))
      throw new Exception('php cURL extension must be installed and enabled');
  
    $this->curl = curl_init();
    curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookiefile);
	
    $this->Login();
  }

  function __destruct()
  {
    $this->Logout();
    unlink($this->cookiefile);
  }

  private function syno_success()
  {
    if ($this->response->success)
      return true;
  
    if(isset($this->response->error->code))
      throw new Exception('SYNO Error (code = ' . $this->response->error->code. ')');
    else
      throw new Exception('Unknown error' . $this->response);
  }

  public function Request($path)
  {
    if($this->token != '')
      $params = '&SynoToken=' . $this->token;
    else
      $params = '';
  
    curl_setopt($this->curl, CURLOPT_URL, $this->url . $path . $params);
    $this->response = json_decode(curl_exec($this->curl));
      return $this->syno_success();
  }

  public function Login()
  {
    if($result = $this->Request('/webman/login.cgi?username=' . $this->username . '&passwd=' . $this->password . '&enable_syno_token=yes'))
      $this->token = $this->response->SynoToken;
    return $result;
  }

  public function Logout()
  {
    curl_setopt($this->curl, CURLOPT_URL, $this->url . '/webman/logout.cgi');
    curl_exec($this->curl);
    $this->token = '';
  }

  public function SearchCertificates()
  {
    return $this->Request('/webapi/entry.cgi?api=SYNO.Core.Certificate.CRT&method=list&version=1');
  }
  
  public function UpdateCertificate($certname, $key, $cert, $chain)
  {
    if(!$this->SearchCertificates())
      return false;

    $id='';
    $desc='';
    $default='false';
    foreach($this->response as $crt)
      if($crt->subject->common_name == $certname) {
        $id = $crt->id;
        $desc = $crt->desc;
        if($crt->is_default == '1')
          $default = 'true';
      }

    $post = new multipart_data();
    $post->addfile('key', $key);
    $post->addfile('cert', $cert);
    $post->addfile('inter_cert', $chain);
    $post->addpart('id', $id);
    $post->addpart('desc', $desc);
    $post->addpart('as_default', $default);
    $boundary = $post->get_boundary();
    $post = $post->get_postdata();
	
    curl_setopt($this->curl, CURLOPT_HTTPHEADER, array("Content-Type: multipart/form-data; boundary=$boundary","Content-Length: " . strlen($post)));
    curl_setopt($this->curl, CURLOPT_POST, 1);
    curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
    curl_setopt($this->curl, CURLOPT_URL, $this->url . "/webapi/entry.cgi?api=SYNO.Core.Certificate&method=import&version=1&SynoToken=$this->token");
    $this->response = json_decode(curl_exec($this->curl));
    return $this->syno_success();
  }
  
}

class multipart_data
{

  private $postdata;
  private $boundary;

  function __construct()
  {
    $this->postdata = '';
    $this->boundary = "---------------------" . md5(mt_rand() . microtime());
  }
  
  public function get_postdata()
  {
    return $this->postdata . "--" . $this->boundary . "--\r\n";
  }
  
  public function get_boundary()
  {
    return $this->boundary;
  }

  public function addpart($name, $data, $filename = null)
  {
    if (isset($filename))
      $filename = "; filename=\"$filename\"";
    else
      $filename = '';

    $this->postdata .= "--$this->boundary\r\n" .
                       "Content-Disposition: form-data; name=\"$name\"$filename\r\n" .
                       "Content-Type: application/x-x509-ca-cert\r\n\r\n" .
                       "$data\r\n";
  }

  public function addfile($name, $filename)
  {
    $data = file_get_contents($filename);
    $this->addpart($name, $data, $filename);
  }

}
