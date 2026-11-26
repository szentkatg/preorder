# Deployment workflow

- For explicitly requested routine NAS/staging deployments, use the stable
  `infrastructure/scripts/deploy-preorder.ps1` entrypoint documented in
  `docs/preorder-deployment.md`, from the `feature/pricing-module` worktree.
- Keep the invocation prefix exactly
  `powershell.exe -NoProfile -File infrastructure/scripts/deploy-preorder.ps1`.
  Do not create a newly named `.tmp` deployment script for each release.
- Review, test and commit requested changes before deploying. The helper does
  not commit files and refuses special schema/build/configuration releases;
  prepare those explicitly without weakening the approval policy.
- Production deployment requires separate explicit authorization and its own
  reviewed workflow. Never extend this NAS/staging helper to production merely
  to reuse an approval.
- User deployment shorthand (agreed 2026-11-26): a deployment request saying
  "tesztre" authorizes deployment to BOTH NAS and staging/test. A deployment
  request saying "élesre" authorizes NAS, staging/test AND production, using
  the separate reviewed production workflow. No extra target clarification is
  needed for these requests. Quoted examples, questions and rule-setting messages
  are not deployment requests. Backups, verification, schema preparation and
  required tool approvals still apply; report any failed prerequisite before
  proceeding to production.
- Do not modify global permission settings or request blanket PowerShell/SSH
  access to avoid prompts. Persistent narrow approvals depend on the user's UI
  and managed policy.
