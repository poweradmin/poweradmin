CREATE TABLE domains (
 id		 NUMBER,
 name		 VARCHAR(255) NOT NULL,
 master		 VARCHAR(128) DEFAULT NULL,
 last_check	 INT DEFAULT NULL,
 type		 VARCHAR(6) NOT NULL,
 notified_serial INT DEFAULT NULL, 
 account         VARCHAR(40) DEFAULT NULL,
 primary key (id)
);
CREATE SEQUENCE DOMAINS_ID_SEQUENCE; 
CREATE INDEX DOMAINS$NAME ON Domains (NAME);

 
CREATE TABLE records (
        id              number(11) not NULL,
        domain_id       INT DEFAULT NULL REFERENCES Domains(ID) ON DELETE CASCADE,
        name            VARCHAR(255) DEFAULT NULL,
        type            VARCHAR(6) DEFAULT NULL,
        content         VARCHAR(255) DEFAULT NULL,
        ttl             INT DEFAULT NULL,
        prio            INT DEFAULT NULL,
        change_date     INT DEFAULT NULL, 
	primary key (id)
);

CREATE INDEX RECORDS$NAME ON RECORDS (NAME);
CREATE SEQUENCE RECORDS_ID_SEQUENCE;

CREATE TABLE supermasters (
	  ip VARCHAR(25) NOT NULL, 
	  nameserver VARCHAR(255) NOT NULL, 
	  account VARCHAR(40) DEFAULT NULL
);

