pan-os-php
================


PAN-OS-PHP is a PHP library aimed at making PANOS config changes easy (and XML free ;), 
maintainable and allowing complex scenarios like rule merging, unused object tracking,
conversion of checkpoint exclusion groups, massive rule editing, AppID conversion … . 
It will work seamlessly on PAN-OS local xml config file or PAN-OS API.

Support Me
============

Do you like PAN-OS-PHP? Are you looking forward to the **new features**?

US Dollar
----------
<a href="https://buymeacoffee.com/panosphp" target="_blank">
<img src="https://github.com/swaschkut/pan-os-php/blob/develop_2-1-X/qrcode_bmc_qr-2.png" alt="PayPal or credit/debit card" width="254" height="248">
</a>

EUR
----------
<a href="https://pan-os-php.sumupstore.com/products" target="_blank">
<img src="https://github.com/swaschkut/pan-os-php/blob/develop_2-1-X/qrcode_sumup.jpeg" alt="PayPal or credit/debit card" width="254" height="248">
</a>

PAYPAL
----------
<img src="https://github.com/swaschkut/pan-os-php/blob/develop_2-1-X/qrcode_paypal.png" alt="PayPal or credit/debit card" width="254" height="248">


Thank you!

INSTALLATION
============

The recommended installation of PAN-OS-PHP is via Docker.
Please install Docker Desktop if needed.

[README docker](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/READMEdocker.md)

[WIKI docker](https://github.com/PaloAltoNetworks/pan-os-php/wiki/docker)


Old detailed information
============
**Homepage** : latest sources on [GitHub](https://github.com/PaloAltoNetworks/pan-os-php).

**Requirements** :
 - PHP 8.3 with curl module [ tested with PHP 8.1 / 8.2 / 8.3 ]
 - php-curl php-dom php-mbstring php-bcmath

**Usage**: include the file lib/pan_php_framework.php in your own script to load the necessary classes.
```php
    require_once "lib/pan_php_framework.php";
```

File tree:
* **/lib/** contains library files source code
* **/utils/** contains ready to run scripts, more information in [utils/README](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/utils/README.md)
* **/doc/index.html**  has all classes documentations
* **/example-xxx.php** are examples about using this library

SUPPORT
============
This tool is provided "AS IS" and is community supported.
Please also check the [LICENSE](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/LICENSE) file.

For help, it is always possible to open a GIT issue for this repository, or reaching out to [Palo Alto Networks LIVE community page](https://live.paloaltonetworks.com/t5/api-articles/pan-os-php-scripting-library-and-utilities/ta-p/404396).

Usage (create custom Scripts)
============

With less than 20 lines of code, you should be able to solve most of your needs. Brief overview:

[README customScripting](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/READMEcustomScripting.md)


UTIL (predefined Scripts)
============

**You hate scripting ?**

Use around 50 different predefined entry parts [e.g. address / service / tag / rule / ...] with 100 of actions and filters to easily improve your Palo Alto Networks Firewall and Panorama configuration

[README util](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/READMEutil.md)
 


Docker build
============

There are Dockerfiles available with OS: Ubuntu20/22 and CentOS 7/8

[README docker](https://github.com/PaloAltoNetworks/pan-os-php/blob/main/READMEdocker.md)

[WIKI docker](https://github.com/PaloAltoNetworks/pan-os-php/wiki/docker)
