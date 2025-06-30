# Poweradmin Dynamic DNS Client Addons

This directory contains client implementations and utilities for Poweradmin's Dynamic DNS functionality. These tools allow users to programmatically update DNS records when their IP addresses change, commonly used for home servers, IoT devices, and other systems with dynamic IP addresses.

## Files Overview

### IP Address Detection Utility

- **`clientip.php`** - Web-based utility that returns the client's public IP address
  - Used by Dynamic DNS clients to detect their current public IP
  - Accessible via HTTP GET request
  - Returns IP address as plain text with HTML escaping for security

### Dynamic DNS Clients

Three client implementations are provided in different programming languages:

- **`dynamic_dns_client.pl`** - Perl implementation
- **`dynamic_dns_client.py`** - Python implementation  
- **`dynamic_dns_client.sh`** - Bash shell script implementation

All clients provide the same core functionality but cater to different environments and user preferences.

## Client Features

### Common Functionality

- **Authentication**: HTTP Basic Authentication support
- **IP Detection**: Automatic public IP discovery via `clientip.php`
- **IP Validation**: IPv4 and IPv6 address validation
- **Error Handling**: Comprehensive error reporting
- **Verbose Output**: Optional detailed status messages
- **Configuration**: Customizable parameters for different environments

### Language-Specific Features

#### Perl Client (`dynamic_dns_client.pl`)
- Uses `LWP::Simple` for HTTP requests
- Regex-based IP validation
- Lightweight and portable
- **Dependencies**: Perl interpreter, `LWP::Simple` module

#### Python Client (`dynamic_dns_client.py`)
- Uses `requests` library for HTTP communications
- **Advanced Feature**: Dual-stack support (IPv4 and IPv6 simultaneously)
- Command-line argument parsing with `argparse`
- Socket patching for forcing IPv4/IPv6 connections
- Most feature-rich implementation
- **Dependencies**: Python 3.x, `requests` library (`pip install requests`)

#### Bash Client (`dynamic_dns_client.sh`)
- Uses `curl` for HTTP requests
- Minimal dependencies (bash + curl)
- Ideal for embedded systems and minimal environments
- Shell-based error handling
- **Dependencies**: Bash shell, `curl` command-line tool

## Usage

### Basic Configuration

Each client requires the following parameters:

- **Base URL**: Your Poweradmin installation URL
- **Username/Password**: Poweradmin user credentials
- **Hostname**: Fully Qualified Domain Name (FQDN) to update
- **IP Address**: Can be auto-detected or manually specified

### Example Usage

#### Perl Client
```bash
perl dynamic_dns_client.pl
```

#### Python Client
```bash
python dynamic_dns_client.py --domain example.com --host myserver
```

#### Bash Client
```bash
./dynamic_dns_client.sh
```

### Configuration Options

**Python Client** supports command-line arguments:
- `-d` / `--dyndns`: Domain name to update
- `-l` / `--login`: Authentication username
- `-p` / `--password`: Authentication password
- `-u` / `--url`: Custom Poweradmin base URL
- `-v` / `--verbose`: Enable detailed output

**Perl and Bash Clients** use hardcoded variables that need to be edited in the script:
- `$login` / `login`: Authentication username
- `$password` / `password`: Authentication password
- `$domain` / `domain`: Domain name to update
- `$poweradmin_url` / `poweradmin_url`: Poweradmin base URL
- `$verbose` / `verbose`: Enable detailed output (1 for enabled, 0 for disabled)

## Integration with Poweradmin

### Authentication Requirements

Users must have one of the following permissions in Poweradmin:
- `zone_content_edit_own`
- `zone_content_edit_own_as_client`  
- `zone_content_edit_others`

### Supported Record Types

- **A Records**: IPv4 addresses
- **AAAA Records**: IPv6 addresses
- **Dual-stack**: Both IPv4 and IPv6 simultaneously (Python client)

### API Endpoints

The clients interact with these Poweradmin endpoints:

1. **`/addons/clientip.php`** - IP address detection
2. **`/dynamic_update.php`** - DNS record updates

The Dynamic DNS functionality provides a flexible and secure way to maintain DNS records for systems with changing IP addresses, with multiple client implementations to suit different technical requirements and environments.