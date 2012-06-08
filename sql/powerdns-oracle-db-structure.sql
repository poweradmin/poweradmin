create table domains (
 id		 NUMBER,
 name		 VARCHAR(255) NOT NULL,
 master		 VARCHAR(128) DEFAULT NULL,
 last_check	 INT DEFAULT NULL,
 type		 VARCHAR(6) NOT NULL,
 notified_serial INT DEFAULT NULL, 
 account         VARCHAR(40) DEFAULT NULL,
 primary key (id)
);
create sequence DOMAINS_ID_SEQUENCE; 
create index DOMAINS$NAME on Domains (NAME);

 
CREATE TABLE records (
        id              number(11) not NULL,
        domain_id       INT DEFAULT NULL REFERENCES Domains(ID) ON DELETE CASCADE,
        name            VARCHAR(255) DEFAULT NULL,
        type            VARCHAR(10) DEFAULT NULL,
        content         VARCHAR2(4000) DEFAULT NULL,
        ttl             INT DEFAULT NULL,
        prio            INT DEFAULT NULL,
        change_date     INT DEFAULT NULL, 
	primary key (id)
);

create index RECORDS$NAME on RECORDS (NAME);
create sequence RECORDS_ID_SEQUENCE;

create table supermasters (
	  ip VARCHAR(25) NOT NULL, 
	  nameserver VARCHAR(255) NOT NULL, 
	  account VARCHAR(40) DEFAULT NULL
);

