# synoapi
This is a PHP Library to manage certificates in DSM / SRM

To use it, just clone it, rename and edit test.php.

The constructor "synoapi" needs 3 parameters:
1) address of your DSM / SRM (example : 'http://localhost:5000')
2) your username
3) your password

The "UpdateCertificate" function need 4 more parameters:
1) your domain name (example : 'yourdomain.com')
2) the filename of your private key
3) the filename of your certificate
4) the filename of your chain of trust

Example for letsencrypt's directories:
/etc/letsencrypt/live/yourdomain.com/privkey.pem
/etc/letsencrypt/live/yourdomain.com/cert.pem
/etc/letsencrypt/live/yourdomain.com/chain.pem
