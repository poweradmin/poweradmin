## Basic Vagrant support

This version only supports the MySQL PowerDNS backend at the moment.

### Requirements:
* Vagrant (https://www.vagrantup.com)

### Provisioned versions
A new VM will be provisioned with following items:
* CentOS 8.x
* MariaDB
* PHP version 5.6
* PowerDNS v3.x
* bind-utils (host, dig, etc.)

### Start the virtual machine
Enter the directory and type:
```vagrant up```

### Running Vagrant on an M1 Apple Silicon using Docker
To get Vagrant running using Docker you need:
- login into docker `docker login`
- run vagrant machine `vagrant up --provider=docker`

### Access to the environment
Via SSH:
```vagrant ssh```

Via Web: http://poweradmin.local

Configure Poweradmin via: http://poweradmin.local/install/

_(Tested on OSX 12.1)_
