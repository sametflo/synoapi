<?php

include_once 'api/synoapi.php';

$syno = new synoapi('http://localhost:5000', 'username', 'password');

if($syno->UpdateCertificate('yourdomain.com', 'privkey.pem', 'cert.pem', 'chain.pem'))
  echo("Certificat updated\n");
