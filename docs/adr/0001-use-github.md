\# ADR-0001



\## Title



Use GitHub as the single source of truth



\## Status



Accepted



\## Context



The project previously relied on manual file copies between environments.



This caused inconsistencies and made development difficult.



\## Decision



Use GitHub as the central source code repository.



Development workflow:



Windows

→ Commit

→ Push



NAS

→ Git Pull



\## Consequences



Advantages



\- Version history

\- Branches

\- Easy rollback

\- Safe development



Disadvantages



\- Requires Git workflow

