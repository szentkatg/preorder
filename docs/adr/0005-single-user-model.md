\# ADR-0005



\## Title



Use a single user model for all application users



\## Status



Accepted



\## Context



The current system uses separate user tables:



\- users

\- partner\_users



This creates duplicated authentication logic, separate guards, and makes permission handling more complex.



The new permission system should support:



\- Filament admin access

\- Partner portal access

\- Sales users

\- Partner users

\- Multi-partner users

\- Function permissions

\- Data access scopes



\## Decision



PreOrder 2.0 will use a single user model.



All users will be stored in the `users` table.



Access to different parts of the system will be controlled by permissions:



\- panel.admin.access

\- panel.partner.access



Roles and permissions will define what a user can do.



Access scopes will define which partners, addresses, brands, seasons or order sheet types the user can access.



\## Consequences



\### Advantages



\- One authentication model

\- One permission system

\- Easier Filament integration

\- Easier partner portal integration

\- No need for separate sales\_rep logic

\- Users can access multiple partners with the same email address

\- More flexible long-term architecture



\### Disadvantages



\- Requires migration from partner\_users to users

\- Existing auth guards must be refactored

\- Existing partner login flow must be adapted

\- Compatibility layer will be needed during transition



\## Migration strategy



The migration will be gradual.



1\. Keep existing `partner\_users` table temporarily.

2\. Add new IAM tables.

3\. Create compatibility methods.

4\. Migrate partner users into `users`.

5\. Update authentication.

6\. Remove old partner guard only after the new system is stable.

