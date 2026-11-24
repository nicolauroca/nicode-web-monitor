# Working agreement

Read `docs/README.md` and `docs/development-context.txt` before making changes.

- Keep `docs` current in the same change as the implementation. Replace obsolete
  instructions; historical evidence belongs in Git history, not active guides.
- This is pre-beta development. Backward compatibility with earlier development
  releases is not a requirement. Do not add migration layers for that purpose.
- A Joomla can be central, monitored, or both. Keep the two component identities
  and the two package owners independent. Exercise self-monitoring in tests.
- End users install the `pkg_` archives and connect from the component UI; UUIDs,
  inventory hashes, IP selection and task parameters are implementation details.
- Preserve HTTPS verification, validated/pinned public destinations, separate
  maintenance permissions and credentials, explicit maintenance confirmation,
  and reconciliation of uncertain effects.
- Only disposable installations may be used for write fixtures. Never put
  credentials, inventories, database dumps or generated environments into Git.
