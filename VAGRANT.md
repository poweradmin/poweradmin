### Basic Vagrant support

This version only supports the MySQL PowerDNS backend at the moment.

#### Requirements: ####
* Vagrant (https://www.vagrantup.com)

#### How to start the VM ####
Enter the directory and type:
```vagrant up```

A new VM will be provisoned with following items:
* CentOS 7.x
* Mariadb
* PHP version 5.6
* PowerDNS v3.x
* bind-utils (host, dig, etc.)


#### Access to the environment ####

Via SSH:
```vagrant ssh```

Via Web:
http://poweradmin.local
Configure Poweradmin via: http://poweradmin.local/install/


_(Tested on OSX 10.10.5)_
