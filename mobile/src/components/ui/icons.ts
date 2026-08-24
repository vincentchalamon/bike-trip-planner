// Icon parity with the web (lucide). Re-export the curated set the mobile
// screens use so call sites import from one place. Add icons here as needed.
//
// Deep-imports each icon from its own `lucide-react-native/dist/esm/icons/*.js`
// module instead of the package barrel (`lucide-react-native`), which
// re-exports the full ~3300-icon set and defeats tree-shaking (#1176).
// Several names below are legacy aliases: the real file lucide ships under
// differs from the exported name (e.g. `AlertTriangle` lives in
// `triangle-alert.js`, `HelpCircle` in `circle-question-mark.js`,
// `MoreVertical` in `ellipsis-vertical.js`). This mapping was read off the
// package's own barrel (`lucide-react-native/dist/esm/lucide-react-native.js`)
// for the installed version (v0.545.0) — it is not a published contract, so
// re-verify it (or regenerate from that barrel) after any lucide-react-native
// upgrade.
export { default as AlertTriangle } from 'lucide-react-native/dist/esm/icons/triangle-alert.js';
export { default as ArrowLeft } from 'lucide-react-native/dist/esm/icons/arrow-left.js';
export { default as Bike } from 'lucide-react-native/dist/esm/icons/bike.js';
export { default as Calendar } from 'lucide-react-native/dist/esm/icons/calendar.js';
export { default as Check } from 'lucide-react-native/dist/esm/icons/check.js';
export { default as ChevronRight } from 'lucide-react-native/dist/esm/icons/chevron-right.js';
export { default as CloudRain } from 'lucide-react-native/dist/esm/icons/cloud-rain.js';
export { default as Coffee } from 'lucide-react-native/dist/esm/icons/coffee.js';
export { default as Copy } from 'lucide-react-native/dist/esm/icons/copy.js';
export { default as Download } from 'lucide-react-native/dist/esm/icons/download.js';
export { default as FileUp } from 'lucide-react-native/dist/esm/icons/file-up.js';
export { default as Inbox } from 'lucide-react-native/dist/esm/icons/inbox.js';
export { default as Map } from 'lucide-react-native/dist/esm/icons/map.js';
export { default as MapPin } from 'lucide-react-native/dist/esm/icons/map-pin.js';
export { default as Menu } from 'lucide-react-native/dist/esm/icons/menu.js';
export { default as Mountain } from 'lucide-react-native/dist/esm/icons/mountain.js';
export { default as Pencil } from 'lucide-react-native/dist/esm/icons/pencil.js';
export { default as Plus } from 'lucide-react-native/dist/esm/icons/plus.js';
export { default as RefreshCw } from 'lucide-react-native/dist/esm/icons/refresh-cw.js';
export { default as Route } from 'lucide-react-native/dist/esm/icons/route.js';
export { default as Search } from 'lucide-react-native/dist/esm/icons/search.js';
export { default as Settings } from 'lucide-react-native/dist/esm/icons/settings.js';
export { default as Tent } from 'lucide-react-native/dist/esm/icons/tent.js';
export { default as Trash2 } from 'lucide-react-native/dist/esm/icons/trash-2.js';
export { default as X } from 'lucide-react-native/dist/esm/icons/x.js';
// Share feature icon (#1048) — appended so the sorted set above stays untouched.
export { default as Share2 } from 'lucide-react-native/dist/esm/icons/share-2.js';
// Spike-UX restyle: icons used by the redesigned screens (login/create/roadbook/
// share/map/stage-detail). Appended to keep the sorted set above stable.
export { default as CloudSun } from 'lucide-react-native/dist/esm/icons/cloud-sun.js';
export { default as FileText } from 'lucide-react-native/dist/esm/icons/file-text.js';
export { default as Flag } from 'lucide-react-native/dist/esm/icons/flag.js';
export { default as Gauge } from 'lucide-react-native/dist/esm/icons/gauge.js';
export { default as ImageIcon } from 'lucide-react-native/dist/esm/icons/image.js';
export { default as KeyRound } from 'lucide-react-native/dist/esm/icons/key-round.js';
export { default as Link2 } from 'lucide-react-native/dist/esm/icons/link-2.js';
export { default as Lock } from 'lucide-react-native/dist/esm/icons/lock.js';
export { default as Mail } from 'lucide-react-native/dist/esm/icons/mail.js';
export { default as Minus } from 'lucide-react-native/dist/esm/icons/minus.js';
export { default as MoreVertical } from 'lucide-react-native/dist/esm/icons/ellipsis-vertical.js';
// Resupply block (#1105): sectioned food/water suggestions.
export { default as ShoppingBag } from 'lucide-react-native/dist/esm/icons/shopping-bag.js';
// Notifications screen (#1120): permission banner + per-category icons.
export { default as Bell } from 'lucide-react-native/dist/esm/icons/bell.js';
export { default as BellOff } from 'lucide-react-native/dist/esm/icons/bell-off.js';
export { default as CheckCircle2 } from 'lucide-react-native/dist/esm/icons/circle-check.js';
// Offline map degradation (#1148): discrete "offline map" indicator.
export { default as CloudOff } from 'lucide-react-native/dist/esm/icons/cloud-off.js';
// In-ride mode (#1149): help bubble, offline badge, GPS position.
export { default as HelpCircle } from 'lucide-react-native/dist/esm/icons/circle-question-mark.js';
export { default as Navigation } from 'lucide-react-native/dist/esm/icons/navigation.js';
export { default as WifiOff } from 'lucide-react-native/dist/esm/icons/wifi-off.js';
// In-ride nearby-pois (#1150): the 8 intent chips + POI card affordances
// (Search/Tent/AlertTriangle already exported above).
export { default as Clock } from 'lucide-react-native/dist/esm/icons/clock.js';
export { default as Cross } from 'lucide-react-native/dist/esm/icons/cross.js';
export { default as Droplet } from 'lucide-react-native/dist/esm/icons/droplet.js';
export { default as ExternalLink } from 'lucide-react-native/dist/esm/icons/external-link.js';
export { default as Phone } from 'lucide-react-native/dist/esm/icons/phone.js';
export { default as ShoppingCart } from 'lucide-react-native/dist/esm/icons/shopping-cart.js';
export { default as TrainFront } from 'lucide-react-native/dist/esm/icons/train-front.js';
export { default as UtensilsCrossed } from 'lucide-react-native/dist/esm/icons/utensils-crossed.js';
export { default as Wrench } from 'lucide-react-native/dist/esm/icons/wrench.js';
export { default as Zap } from 'lucide-react-native/dist/esm/icons/zap.js';
// In-ride maquette conformity (#1094): disclaimer banner + detour badge.
export { default as CornerUpLeft } from 'lucide-react-native/dist/esm/icons/corner-up-left.js';
export { default as Info } from 'lucide-react-native/dist/esm/icons/info.js';
