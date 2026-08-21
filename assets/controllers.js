import { startStimulusApp } from '@symfony/stimulus-bundle';

// Front-end controllers, used on public pages. Loaded as its own <script type="module"> tag (see importmap.php), starts its own Stimulus app
const app = startStimulusApp();

// Dynamic import() so AssetMapper marks these lazy: an importmap entry, but no <link rel="modulepreload">
// Keys are the Stimulus identifiers as registered, matching what the templates write in data-controller
const LAZY_CONTROLLERS = {
    basket: () => import('./js/basket.js'),
};

const registered = new Set();

// Registers only the lazy controllers this document actually contains - the layout loads this barrel site-wide, while the basket is on the shop, basket and campaign pages alone. Stimulus connects a controller as soon as it is registered, so a late registration still picks up elements already in the DOM
function registerPresentControllers() {
    for (const [identifier, load] of Object.entries(LAZY_CONTROLLERS)) {
        if (registered.has(identifier) || !document.querySelector(`[data-controller~="${identifier}"]`)) {
            continue;
        }

        registered.add(identifier);
        load().then((module) => app.register(identifier, module.default));
    }
}

registerPresentControllers();

// Turbo swaps the <body> without re-running this module, so a page reached by navigation would otherwise never get its own lazy controllers - the add buttons of a product reached from the shop index would simply never answer a click
document.addEventListener('turbo:load', registerPresentControllers);
