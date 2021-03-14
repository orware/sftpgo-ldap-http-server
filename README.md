# sftpgo-ldap-http-server

Simple integration for use with SFTPGo's External Authentication capabilities.

This project differs from the earlier `sftpgo-ldap` repository since it uses the `amphp/http-server` package to allow for PHP to act as a lightweight HTTP server.

I'll probably also experiment with the RoadRunner / Spiral Framework project as well at some point as offers a similar capability, but I ended up starting with the `amphp/http-server` solution to see if it would be successful first.

To keep things simpler for users, even though you could install PHP on the intended server first, and clone this repo into it, I've created a ZIP that includes a binary created using `ExeOutput for PHP` ahead of time that embed the PHP runtime and LDAP and Socket extensions, along with the `/vendor` code from the dependencies used here. You can still customize the functions.php file and configuration.php file which are located in the '/Data' folder after unzipping, just like you can with the `sftpgp-ldap` repository solutions.

The main reason for creating this alternative option is because, I had observed that setting SFTPGo's `external_auth_hook` to point to the EXE option in my `sftpgo-ldap` repository seemed to incur a considerable lag time for the authentication process, and using an HTTP URL for the hook seemed to be considerably faster.

However, I wanted to somehow bring in a simple HTTP server that also allowed me to use most of the existing PHP code I had created and the existing PHP libaries I'm using (in particular, the LdapRecord library for PHP is pretty awesome, so it helps simplify the LDAP interactions), so that's where `amphp/http-server` came into the picture (along with ExeOutput for PHP to help with creating a binary afterwards to simplify things even further).

Right now, the intention of this HTTP server is not to be publicly accessible (I have no idea if it would run well in that situation or if it would run into issues), but at the very least since I didn't build-in any sort of HTTPS support, if you did want to do that you would need to use a reverse proxy in front of it to provide HTTPS support.

Instead, the intention of this HTTP server is for it to run locally on the server you are also running SFTPGo on, and the port being used by this project should be behind a firewall so that only local requests can access the endpoint.

### Quick Instructions (this is only if you want to setup PHP separately on your server and clone the repository):

NOTE: You will need to run this code via something like: `php index.php` at the command line on your computer/server.

* Once cloned, make sure to run `composer install` to add in the amphp, LdapRecord, and Monolog dependencies.
* Copy `configuration.example.php` to `configuration.php` and then begin making adjustments (primarily, you should add `$connections`, adjust `$home_directories`, and add `$virtual_folders`, if desired, and edit the `$default_output_object` if you need to since that's used as a template for what's passed back to SFTPGo).
* You can adjust the `$port` value to allow the server to run on a different port.
* You can add additional `allowed_ips` for the PHP code to respond to (I added my remote IP of the SFTPGo server and my home IP in addition to the localhost related ones).
* You can add one or more named LDAP connections, each pointing to a different LDAP server (if needed) or simply to different Organizational Units. (e.g. one for staff, one for students, and possibly others for different use cases). Each of the connections will be tried in order.
* In addition to the named connections, you will need to define a home directory for each of the named LDAP connections too. These would correspond to directories on the SFTPGo server.
* You may also define one or more virtual directories that would be displayed to users as well after they login.
* Placeholder support is present for the `#USERNAME#` key (for any home directories you define, or for the `name` and `mapped_path` keys when defining virtual directories), which you can use so that each LDAP user would automatically be assigned their own user-specific folder within the home directory defined for the LDAP connection (e.g. if `C:\test\#USERNAME#` is the home directory and my username is `example` then when I login via SFTP I would have the `C:\test\example` folder created where my files would be placed).
* There is a default output object template in the configuration that can be edited if you wanted a different set of defaults to be applied for your users (currently, the only parts that will be changed in the final object response are the `username` and `home_dir` values, and any virtual folders defined will be added as the response object is being generated, since extra processing of the `#USERNAME#` placeholders may be needed).

### Quick Instructions for Using Provided ZIP Package:

* A ZIP file will be attached that already has the amphp/LdapRecord/Monolog dependencies included.
* Once unzipped, you will see a `sftpgo-ldap-http-server.exe` along with a `Data` folder .
* The `Data` folder should only contain the `configuration.example.php` file (which should be copied and named `configuration.php` and customized for your environment), the `functions.php` file (if you may have a specific tweak needed since the current file is mainly setup for an Active Directory environment), along with a `logs` folder which will only log info if you have that flag enabled in your configuration.
* The rest of the configuration related comments shared above in the other instructions would still apply.
* Once configured, you can open up a command prompt in the directory you unzipped the files into and run the `sftpgo-ldap-http-server.exe` and it should start up the simple HTTP server and you can then configure SFTPGo with:`external_auth_hook` set to `http://localhost:9001/` and restart the SFTPGo service to give it a try.
* Once you've been able to verify that things are working as expected, you can use something like the nssm utility and set the EXE to be able to run as a service on your Windows server.
* NOTE: (An OpenLDAP folder may be included in the ZIP package, but it is not needed directly by the EXE, so it can be deleted if you don't need it...it is mainly provided as a convenience, allowing you to easily copy that folder into your `C:` root if you don't already have it there to help with the TLS related issues shared below).

### Server Side Tips:

* You will need to have PHP with the LDAP (and Sockets) extension installed on your server for this project to function.
* If using TLS, the tip on this page (https://ldaprecord.com/docs/core/v2/configuration/#debugging) may be helpful since the `TLS_REQCERT never` option may need to be added locally if testing on Windows (the file `C:\OpenLDAP\sysconf\ldap.conf` will likely need to be created and that config line added to it) or on your live server (Linux: `/etc/ldap/ldap.conf`) along with the "proper" way also described on the page.
* To run a basic test without SFTPGo, you may adjust `_SFTPGO_DEBUG` inside of `configuration.php` to `true` and then adjust the `$debug_object` with the username/password of a real account and see if you successfully receive a JSON response object back, which would indicate the authentication was successful against one of your LDAP connections. If you do use this feature, make sure to turn it back off again, since it will prevent normal logins from working (since it'll always use the `$debug_object`.
* Basic logging has also been added that you can temporarily enable to get a better idea for where you may be having a problem by setting `_SFTPGO_LOG` to true (and a new file for the day should be created in the logs folder).

I hope this is helpful for others wanting to make use of SFTPGo and LDAP/Active Directory!