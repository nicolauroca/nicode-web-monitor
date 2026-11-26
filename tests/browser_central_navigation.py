"""Follow the same directory -> site -> section -> category links as an operator."""


def enter_site(page, central, section='overview', category='', label='Self monitored central'):
    page.goto(central); page.wait_for_load_state('networkidle')
    page.get_by_role('link', name='Entrar en ' + label, exact=True).click()
    page.wait_for_load_state('networkidle')
    if section != 'overview':
        names = {'health':'Diagnóstico','inventory': 'Inventario', 'history': 'Historial', 'connection': 'Conexión', 'maintenance': 'Mantenimiento'}
        page.locator('.nwm-site-tabs').get_by_role('link', name=names[section], exact=True).click()
        page.wait_for_load_state('networkidle')
    if category:
        open_category(page, category)
    return page.url


def open_category(page, category):
    page.locator('.nwm-site-tabs').get_by_role('link', name='Inventario', exact=True).click()
    page.wait_for_load_state('networkidle')
    page.locator('[data-nwm-category-link=' + category + ']').click()
    page.wait_for_load_state('networkidle')
