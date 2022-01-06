# -*- mode: ruby -*-
# vi: set ft=ruby :

REQUIRED_PLUGINS = %w(vagrant-hostmanager vagrant-vbguest)
REQUIRED_PLUGINS_VERSIONS = {}
REQUIRED_PLUGINS.each do |plugin|
  unless Vagrant.has_plugin?(plugin) || ARGV[0] == 'plugin' then
    version = REQUIRED_PLUGINS_VERSIONS[plugin].nil? ? '' : "--plugin-version=#{REQUIRED_PLUGINS_VERSIONS[plugin]}"
    system "vagrant plugin install #{plugin} #{version}"
    exec "vagrant #{ARGV.join(" ")}"
  end
end

Vagrant.configure(2) do |config|
  config.vm.box = "centos/8"
  config.vm.hostname = "poweradmin.local"
  config.vm.box_check_update = true

  config.hostmanager.enabled = true
  config.hostmanager.manage_host = true

  config.vm.network "private_network", ip: "192.168.33.10"
  config.vm.synced_folder "./", "/var/www/html/", owner: 48 # Apache

  # Provider for VirtualBox
  config.vm.provider "virtualbox" do |vb|
    vb.gui = false
    vb.memory = "1024"
    vb.cpus = 2
  end

  # Provider for Docker
  config.vm.provider :docker do |docker, override|
    override.vm.box = nil
    docker.image = "centos:8"
    docker.remains_running = true
    override.ssh.insert_key = true
    docker.has_ssh = true
    docker.privileged = true
  end

  config.vm.provision "shell", inline: <<-SHELL
     # sudo yum -y update
     sudo yum -y install mariadb-server mariadb
     sudo yum -y install epel-release httpd
     sudo yum -y install pdns-backend-mysql pdns
     sudo yum -y install php-fpm php-cli php-mysqlnd

     # Autostart services on reboot
     sudo systemctl enable mariadb
     sudo systemctl enable httpd
     sudo systemctl enable pdns
     sudo systemctl enable php-fpm

     cat << EOT >> /etc/httpd/conf.modules.d/02-php.conf
      <FilesMatch \.php$>
         SetHandler "proxy:fcgi://127.0.0.1:9000"
      </FilesMatch>

     DirectoryIndex index.php
EOT

     # Disable SELinux
     echo "Disable SELinux"
     sudo cat << EOT >> /etc/selinux/config
     SELINUX=disabled
     SELINUXTYPE=targeted
EOT

     sudo setenforce 0
     echo "Setup database"
     sudo systemctl start mariadb
     sudo mysqladmin create pdns
     sudo mysql -u root -e "GRANT ALL ON pdns.* TO 'poweradmin'@'localhost' IDENTIFIED BY 'poweradmin'"
     mysql -u root pdns << EOT
CREATE TABLE domains (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255) NOT NULL,
  master                VARCHAR(128) DEFAULT NULL,
  last_check            INT DEFAULT NULL,
  type                  VARCHAR(6) NOT NULL,
  notified_serial       INT UNSIGNED DEFAULT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE UNIQUE INDEX name_index ON domains(name);


CREATE TABLE records (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT DEFAULT NULL,
  name                  VARCHAR(255) DEFAULT NULL,
  type                  VARCHAR(10) DEFAULT NULL,
  content               VARCHAR(64000) DEFAULT NULL,
  ttl                   INT DEFAULT NULL,
  prio                  INT DEFAULT NULL,
  disabled              TINYINT(1) DEFAULT 0,
  ordername             VARCHAR(255) BINARY DEFAULT NULL,
  auth                  TINYINT(1) DEFAULT 1,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX nametype_index ON records(name,type);
CREATE INDEX domain_id ON records(domain_id);
CREATE INDEX ordername ON records (ordername);


CREATE TABLE supermasters (
  ip                    VARCHAR(64) NOT NULL,
  nameserver            VARCHAR(255) NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' NOT NULL,
  PRIMARY KEY (ip, nameserver)
) Engine=InnoDB CHARACTER SET 'latin1';


CREATE TABLE comments (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  name                  VARCHAR(255) NOT NULL,
  type                  VARCHAR(10) NOT NULL,
  modified_at           INT NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  comment               TEXT CHARACTER SET 'utf8' NOT NULL,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX comments_name_type_idx ON comments (name, type);
CREATE INDEX comments_order_idx ON comments (domain_id, modified_at);


CREATE TABLE domainmetadata (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  kind                  VARCHAR(32),
  content               TEXT,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX domainmetadata_idx ON domainmetadata (domain_id, kind);


CREATE TABLE cryptokeys (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  flags                 INT NOT NULL,
  active                BOOL,
  published             BOOL DEFAULT 1,
  content               TEXT,
  PRIMARY KEY(id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX domainidindex ON cryptokeys(domain_id);


CREATE TABLE tsigkeys (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255),
  algorithm             VARCHAR(50),
  secret                VARCHAR(255),
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE UNIQUE INDEX namealgoindex ON tsigkeys(name, algorithm);

EOT

cat <<EOT >> /etc/pdns/pdns.conf
launch=gmysql
gmysql-host=127.0.0.1
gmysql-user=root
gmysql-dbname=pdns
gmysql-password=

EOT
     sudo systemctl start pdns
     sudo systemctl start php-fpm
     sudo systemctl start httpd

     echo "READY: Poweradmin is available via http://poweradmin.local/install"
     echo "Database: pdns"
     echo "Mysql user: poweradmin"
     echo "Mysql password: poweradmin"
  SHELL
end
