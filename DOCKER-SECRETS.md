# Docker Secrets Support for Poweradmin

The Poweradmin container can read any environment variable from a file instead of
taking it directly, so passwords, API keys and certificates never appear in the
container's environment. Append `__FILE` to the variable name and point it at the
file:

```
-e DB_PASS__FILE=/run/secrets/db_password
```

Secrets are resolved before the container decides how to configure itself, so
`VAR__FILE` behaves exactly like `VAR` everywhere, including `PA_CONFIG_PATH__FILE`.
Setting both `VAR` and `VAR__FILE` for the same variable is rejected at startup.

**Full documentation: <https://docs.poweradmin.org/installation/docker-secrets/>**

It covers the Docker run, Compose and Swarm forms, the complete list of secret
variables for the database, PowerDNS API, mail, LDAP, OIDC, SAML and the initial
admin user, multi-line secrets for certificates and private keys, how a
configuration file interacts with environment variables, and troubleshooting.

See also [DOCKER.md](DOCKER.md) for the full environment variable reference.
