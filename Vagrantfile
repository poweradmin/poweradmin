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

  config.vm.network "private_network", type: "dhcp"
  config.vm.synced_folder "./", "/var/www/html/", owner: 48 # Apache

  # Provider for VirtualBox
  config.vm.provider "virtualbox" do |vb|
    vb.gui = false
    vb.memory = "1024"
    vb.cpus = 2
  end

  config.vm.provision :shell, path: "vagrant/provision-centos.sh"
end
