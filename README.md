WSDL EOL schedule:

https://www.fedex.com/en-us/developer/web-services.html

Caution: FedEx Web Services Tracking, Address Validation, and Validate Postal Codes WSDLS will be retired on May 15, 2024. The SOAP based FedEx Web Services is in development containment and has been replaced with FedEx RESTful APIs. To learn more and upgrade your integration from Web Services to FedEx APIs, please visit the FedEx Developer Portal.

# FedEx Ground Economy / SmartPost for Zen Cart Mod

Updated 10/25/2023. Following code is added into regular FedEx shipping module.

This module is based on the Numinix FedEx Smartpost Shipping Module, v1.3.1 with some modifications/updates to support newer Zen Cart version. Tested in ZC 1.5.7d with PHP 7.4

https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-smartpost-shipping-module

The following four numbers are needed to use this plug-in: 
FedEx Key, FedEx Password, FedEx Account Number, FedEx Meter Number

I copied the current FedEx Key and FedEx password from the latest fedexwebservices. Then I put my Acct# and Meter# there, and it worked. Please also note, FedEx doesn't provide new product keys any more. This mod is only for old users. 

If unable to fix the warning, add "error_reporting(0);" to the top line.


Changelog:
1. Updated code for PHP7.4
2. Corrected debug function. There are some lines of code for debugging but not working.
3. Updated the language to display Ground Economy instead of Smartpost
4. Used the newer pricing file (RateService_v31) instead of v10. Also changed the folder location for the pricing file so it can use the same file from fedexwebservices folder instead.
5. Correct multiple PHP warnings when the module isn't installed (Special thanks to @lat9)
6. Pre-define all class variables (aka properties) to prevent PHP 8.2 deprecation logs (Special thanks to @lat9)
7. PHP strftime deprecated as of PHP 8.1 (Special thanks to @lat9)
8. Bug fixes. When weight over 70lb, shipping quote is still displayed. 
