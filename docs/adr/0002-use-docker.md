\# ADR-0002



\## Title



Use Docker for development



\## Status



Accepted



\## Context



The production environment cannot safely be used for architecture changes.



\## Decision



Development is performed inside Docker containers on the Synology NAS.



Containers



\- nginx

\- php

\- mariadb

\- redis

\- mailpit

\- phpmyadmin



\## Consequences



Safe development



Production remains untouched.

