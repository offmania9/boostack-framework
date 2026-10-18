## About Boostack-framework
This repository, **"offmania9/boostack-framework"**, represents the **core module** of the Boostack framework.  
The repository **"offmania9/boostack"** serves as a usage interface for Boostack-framework and includes many **out-of-the-box features** built on top of this core module.

## About Boostack
Boostack is a full stack framework for PHP.
Improve your development and build your ideas

## How to get Boostack
Boostack is available open-source under the MIT license.

## Setup

**Get the latest version via Composer**

`composer create-project offmania9/boostack` - out-of-the-box features built on core module
`composer create-project offmania9/boostack-framework` - core module
offmania9/boostack require/import automatically offmania9/boostack-framework

Import as vendor: composer require offmania9/boostack

## Security defaults

Boostack includes secure session and remember-me handling, centralized sensitive-data
redaction, an optional fail-closed REST endpoint registry, and a shared database-backed
authentication rate limiter.

The base `offmania9/boostack` project installs the required
`boostack_auth_rate_limit` table as part of its initial database. Existing
applications upgrading the framework must add the same table through their own
versioned deployment process. Configure the limiter with:

```php
$config['auth_rate_limit_on'] = true;
$config['auth_rate_limit_table'] = 'boostack_auth_rate_limit';
$config['auth_rate_limit_account_attempts'] = 5;
$config['auth_rate_limit_network_attempts'] = 25;
$config['auth_rate_limit_window_seconds'] = 300;
$config['auth_rate_limit_block_seconds'] = 600;
```

Applications can provide a REST allowlist and request contract by implementing
`Boostack\Models\Rest\RestEndpointRegistryInterface` and setting
`$config['api_endpoint_registry_class']` to that class name. Without this setting,
legacy protected-method discovery remains available for backwards compatibility.

## After you get it
Please refer to the the [Documentation](https://www.getboostack.com/docs) for additional guidance on configuring as 
* a standalone setup with PHP-Apache-MySQL 
* or within Docker containers.

## Docker Installation
Launch Docker compose in project root

`docker-compose up -d --build`

## License
The Boostack framework is open-sourced software licensed under the MIT license. (https://github.com/offmania9/Boostack/blob/master/LICENSE)

## Copyright
Copyright 2014-2026 Spagnolo Stefano
@author Spagnolo Stefano <s.spagnolo@hotmail.it>
@version 6.2
