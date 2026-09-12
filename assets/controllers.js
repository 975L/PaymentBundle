import { startStimulusApp } from '@symfony/stimulus-bundle';

// Front-end controllers on public pages, loaded as their own <script type="module"> tag (see importmap.php), joining the one Stimulus application the other c975L bundles share
globalThis.c975lStimulusApp ??= startStimulusApp();
const app = globalThis.c975lStimulusApp;

// Dynamic import() so AssetMapper marks these lazy: an importmap entry, but no <link rel="modulepreload">
// Keys are the Stimulus identifiers as registered, matching what the templates write in data-controller
const LAZY_CONTROLLERS = {
    basket: () => import('./js/basket.js'),
    giftCard: () => import('./js/gift-card.js'),
};

const registered = new Set();

// Registers only the lazy controllers this document actually contains - the layout loads this barrel site-wide, while the gift card is on the pages showing one. The basket bar carries its own controller and so is on every page of a site that sells, which costs one basket read per page and, once for the browsing session, one timezone post. Stimulus connects a controller as soon as it is registered, so a late registration still picks up elements already in the DOM
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
