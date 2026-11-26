"""Synthetic schema-2 wire data, independent of any installation or credentials."""
import datetime

CATEGORIES = ['joomla', 'runtime', 'database', 'extensions', 'updates', 'configuration',
              'security', 'users', 'content', 'languages', 'templates', 'scheduler', 'storage']


def inventory_fixture(status='complete', collected_at=None):
    sections = {key: {'status': 'denied', 'reason': 'sharing_disabled', 'data': None} for key in CATEGORIES}
    sections['joomla'] = {'status': 'available', 'data': {'version': '6.1.4'}}
    sections['extensions'] = {'status': 'available', 'data': {'items': [], 'total': 0, 'truncated': False}}
    if status == 'partial':
        sections['database'] = {'status': 'error', 'data': None}
    return {'schema_version': 2, 'collected_at': collected_at or datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'status': status, 'execution_context': 'remote_https', 'collector_version': 'test-fixture',
            'capabilities': {'remote_inventory': True, 'remote_updates': False, 'remote_backups': False}, 'sections': sections}
