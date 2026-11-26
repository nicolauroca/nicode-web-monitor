"""Privacy controls in a disposable Joomla. Tokens remain in memory, never in reports."""
from inventory_fixture import CATEGORIES
from browser_central_navigation import enter_site, open_category


def exercise_sharing(page, context, base, central, connector, output, check, fixture, javascript=True):
    identity = fixture({'action': 'fixture_credential'})
    endpoint = base + '/index.php?option=com_nicodewebmonitor&task=inventory'

    def policy(keys):
        page.goto(connector); page.wait_for_load_state('networkidle')
        page.locator('.nwm-sharing-options > summary').click()
        for key in CATEGORIES:
            page.locator('#sharing-' + key).set_checked(key in keys)
        page.get_by_role('button', name='Guardar permisos de consulta', exact=True).click()
        page.wait_for_load_state('networkidle')

    def wire():
        # A caller-supplied category selection must never override the local policy.
        response = context.request.get(endpoint + '&sharing[users]=1&sharing[extensions]=1', headers={'Authorization': 'Bearer ' + identity['token']})
        check(response.status == 200, 'disclosure endpoint remains authenticated and readable')
        return response.json()['inventory']

    def inspect():
        enter_site(page,central)
        navigation=[];statuses=[]
        def observe(request):
            if request.is_navigation_request() and request.frame==page.main_frame:navigation.append(request.method)
        page.on('request',observe)
        def response_status(response):
            if response.request.is_navigation_request() and response.request.method=='POST':statuses.append(response.status)
        page.on('response',response_status)
        page.get_by_role('button', name='Actualizar inventario de Self monitored central', exact=True).click()
        page.wait_for_load_state('networkidle');page.remove_listener('request',observe)
        page.remove_listener('response',response_status)
        check(navigation==['POST','GET'],'successful inventory query redirects to a read-only page')
        check(statuses==[303],'inventory success uses an explicit see-other redirect')

    page.goto(connector); page.wait_for_load_state('networkidle')
    page.locator('.nwm-sharing-options > summary').click()
    check(page.locator('.nwm-sharing-form input[type=checkbox]:checked').count() == 4, 'new categories start denied in the component UI')
    check(context.request.get(connector + '&task=saveSharing').status == 403, 'disclosure cannot be changed through GET')
    check(context.request.post(connector, form={'task': 'saveSharing', 'sharing[users]': '1'}).status == 403, 'disclosure save requires native CSRF')
    if javascript:
        page.get_by_role('button', name='Todo', exact=True).click()
        check(page.locator('.nwm-sharing-form input[type=checkbox]:checked').count() == 13, 'explicit all profile selects thirteen categories')
        check('cambios sin guardar' in page.locator('[data-nwm-sharing-status]').inner_text(), 'profile does not silently save disclosure')
        page.get_by_role('button', name='Guardar permisos de consulta', exact=True).click(); page.wait_for_load_state('networkidle')
    else:
        policy(CATEGORIES)
    check(page.locator('.nwm-sharing-form input[type=checkbox]:checked').count() == 13, 'native save persists all selected categories')
    inventory = wire()
    check(inventory['schema_version'] == 2 and all(inventory['sections'][key]['status'] == 'available' for key in CATEGORIES), 'HTTPS endpoint reads all thirteen permitted categories')
    inspect()
    check(page.locator('[data-nwm-category-link]').count() == 13, 'Central exposes all thirteen inventory sections')
    check(page.locator('[data-category]').count()==0, 'inventory index contains no category payloads')
    open_category(page,'users')
    check(page.locator('[data-category]').count()==1 and page.locator('[data-nwm-category-link],[data-nwm-site]').count()==0,'category page contains only its own payload and no directory')
    check(page.locator('[data-category=users] .nwm-inventory-fields').is_visible(), 'Central shows user aggregates when explicitly permitted')
    navigation=[]
    def read_navigation(request):
        if request.is_navigation_request() and request.frame==page.main_frame:navigation.append(request.method)
    page.on('request',read_navigation)
    page.reload();page.wait_for_load_state('networkidle')
    page.go_back();page.wait_for_load_state('networkidle')
    check(page.locator('[data-nwm-category-link]').count()==13,'browser Back returns from category to inventory directory')
    page.go_forward();page.wait_for_load_state('networkidle');page.remove_listener('request',read_navigation)
    check(page.locator('[data-category=users]').count()==1 and navigation and set(navigation)=={'GET'},'reload and browser Forward preserve category without repeating inventory POST')
    open_category(page,'storage')
    check(page.locator('[data-category=storage]').get_by_text('No es la cuota del alojamiento.', exact=False).count() == 1, 'filesystem capacity is not presented as hosting quota')
    open_category(page,'runtime')
    for width in [1440, 390, 320]:
        page.set_viewport_size({'width': width, 'height': 1000})
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'), f'{width}: all thirteen shared categories fit the viewport')
        if width == 390:
            page.locator('[data-category=runtime]').screenshot(path=str(output / 'runtime-mobile.png'))
            open_category(page,'updates')
            page.locator('[data-category=updates]').screenshot(path=str(output / 'updates-mobile.png'))
            open_category(page,'runtime')
    page.set_viewport_size({'width': 1440, 'height': 1000})
    enter_site(page,central,section='inventory')
    check(page.get_by_text('Última observación guardada', exact=True).is_visible(), 'stored detail is explicitly identified')
    cached = context.request.get(central + '&site=' + str(identity['id']) + '&section=inventory')
    check(cached.status == 200 and 'no-store' in cached.headers.get('cache-control',''), 'stored observation is private and not cacheable by intermediaries')
    check('Abrir este detalle no consulta el sitio' in page.locator('.nwm-inventory').inner_text(), 'cached observation explains its freshness boundary')
    page.locator('.nwm-inventory').screenshot(path=str(output / 'expanded-inventory.png'))
    policy([key for key in CATEGORIES if key not in ['users', 'extensions']])
    inventory = wire()
    check(all(inventory['sections'][key]['status'] == 'denied' and inventory['sections'][key]['data'] is None for key in ['users', 'extensions']), 'HTTP category hints cannot bypass local denial')
    inspect()
    for key in ['users', 'extensions']:
        open_category(page,key)
        section = page.locator('[data-category=' + key + ']')
        check(section.get_by_text('No compartido', exact=True).is_visible() and section.locator('table,dl').count() == 0, key + ': Central distinguishes denial without retaining old fields')
    policy([])
    inventory = wire()
    check(inventory['status'] == 'complete' and all(section['status'] == 'denied' and section['data'] is None for section in inventory['sections'].values()), 'deny-all returns metadata without fabricating a partial failure')
    inspect()
    check(page.locator('.nwm-category-directory').get_by_text('No compartido',exact=True).count()==13, 'deny-all clears the inventory directory without old fields')
    for width in [1440, 390, 320]:
        page.set_viewport_size({'width': width, 'height': 1000})
        page.goto(connector + '&sharing=1#nwm-sharing'); page.wait_for_load_state('networkidle')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'), f'{width}: disclosure options have no page overflow')
        page.locator('.nwm-sharing').screenshot(path=str(output / f'sharing-{width}.png'))
    if javascript:
        page.evaluate("document.documentElement.dataset.bsTheme='dark'")
        page.locator('.nwm-sharing').screenshot(path=str(output / 'sharing-dark.png'))
        page.evaluate("delete document.documentElement.dataset.bsTheme")
    policy(['joomla', 'runtime', 'database', 'extensions'])
    inspect()
    page.set_viewport_size({'width': 1440, 'height': 1000})
