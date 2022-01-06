## Basic Vagrant support

This version only supports the MySQL PowerDNS backend at the moment.

### Requirements:
* Vagrant (https://www.vagrantup.com)

### Provisioned versions
A new VM will be provisioned with following items:
* CentOS 8.x
* MariaDB
* PHP version 5.6
* PowerDNS v4.5.x
* bind-utils (host, dig, etc.)

### Start the virtual machine
Enter the directory and type:
```vagrant up```

### Access to the environment
Via SSH:
```vagrant ssh```

Via Web: http://poweradmin.local

Configure Poweradmin via: http://poweradmin.local/install/

### Tested environments
Ubuntu 20.04.3 LTS (kvm provider)
