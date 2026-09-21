// Icon parity with the web (lucide). Re-export the curated set the mobile
// screens use so call sites import from one place. Add icons here as needed.
//
// Imports each icon from its own `lucide-react-native/icons/*` subpath instead
// of the package barrel (`lucide-react-native`), which re-exports the full
// ~3300-icon set and defeats tree-shaking (#1176). This is the package's public
// per-icon entry point: its `exports` map resolves it to the ESM build under
// Metro and to CJS under Jest, so no module mapping is needed either side.
// (Until lucide-react-native v1 these were deep `dist/esm/icons/*.js` paths;
// v1 renamed those files to `.mjs` and gated `dist/` behind `exports`.)
// Several names below are legacy aliases: the real file lucide ships under
// differs from the exported name (e.g. `AlertTriangle` lives in
// `triangle-alert`, `HelpCircle` in `circle-question-mark`, `MoreVertical` in
// `ellipsis-vertical`). It is not a published contract, so re-verify it after
// any lucide-react-native upgrade.
export { default as AlertTriangle } from 'lucide-react-native/icons/triangle-alert';
export { default as ArrowLeft } from 'lucide-react-native/icons/arrow-left';
export { default as Bike } from 'lucide-react-native/icons/bike';
export { default as Calendar } from 'lucide-react-native/icons/calendar';
export { default as Check } from 'lucide-react-native/icons/check';
export { default as ChevronRight } from 'lucide-react-native/icons/chevron-right';
export { default as CloudRain } from 'lucide-react-native/icons/cloud-rain';
export { default as Coffee } from 'lucide-react-native/icons/coffee';
export { default as Copy } from 'lucide-react-native/icons/copy';
export { default as Download } from 'lucide-react-native/icons/download';
export { default as FileUp } from 'lucide-react-native/icons/file-up';
export { default as Inbox } from 'lucide-react-native/icons/inbox';
export { default as Map } from 'lucide-react-native/icons/map';
export { default as MapPin } from 'lucide-react-native/icons/map-pin';
export { default as Menu } from 'lucide-react-native/icons/menu';
export { default as Mountain } from 'lucide-react-native/icons/mountain';
export { default as Pencil } from 'lucide-react-native/icons/pencil';
export { default as Plus } from 'lucide-react-native/icons/plus';
export { default as RefreshCw } from 'lucide-react-native/icons/refresh-cw';
export { default as Route } from 'lucide-react-native/icons/route';
export { default as Search } from 'lucide-react-native/icons/search';
export { default as Settings } from 'lucide-react-native/icons/settings';
export { default as Tent } from 'lucide-react-native/icons/tent';
export { default as Trash2 } from 'lucide-react-native/icons/trash';
export { default as X } from 'lucide-react-native/icons/x';
// Share feature icon (#1048) — appended so the sorted set above stays untouched.
export { default as Share2 } from 'lucide-react-native/icons/share-2';
// Spike-UX restyle: icons used by the redesigned screens (login/create/roadbook/
// share/map/stage-detail). Appended to keep the sorted set above stable.
export { default as CloudSun } from 'lucide-react-native/icons/cloud-sun';
export { default as FileText } from 'lucide-react-native/icons/file-text';
export { default as Flag } from 'lucide-react-native/icons/flag';
export { default as Gauge } from 'lucide-react-native/icons/gauge';
export { default as ImageIcon } from 'lucide-react-native/icons/image';
export { default as KeyRound } from 'lucide-react-native/icons/key-round';
export { default as Link2 } from 'lucide-react-native/icons/link-2';
export { default as Lock } from 'lucide-react-native/icons/lock';
export { default as Mail } from 'lucide-react-native/icons/mail';
export { default as Minus } from 'lucide-react-native/icons/minus';
export { default as MoreVertical } from 'lucide-react-native/icons/ellipsis-vertical';
// Resupply block (#1105): sectioned food/water suggestions.
export { default as ShoppingBag } from 'lucide-react-native/icons/shopping-bag';
// Notifications screen (#1120): permission banner + per-category icons.
export { default as Bell } from 'lucide-react-native/icons/bell';
export { default as BellOff } from 'lucide-react-native/icons/bell-off';
export { default as CheckCircle2 } from 'lucide-react-native/icons/circle-check';
// Offline map degradation (#1148): discrete "offline map" indicator.
export { default as CloudOff } from 'lucide-react-native/icons/cloud-off';
// In-ride mode (#1149): help bubble, offline badge, GPS position.
export { default as HelpCircle } from 'lucide-react-native/icons/circle-question-mark';
export { default as Navigation } from 'lucide-react-native/icons/navigation';
export { default as WifiOff } from 'lucide-react-native/icons/wifi-off';
// In-ride nearby-pois (#1150): the 8 intent chips + POI card affordances
// (Search/Tent/AlertTriangle already exported above).
export { default as Clock } from 'lucide-react-native/icons/clock';
export { default as Cross } from 'lucide-react-native/icons/cross';
export { default as Droplet } from 'lucide-react-native/icons/droplet';
export { default as ExternalLink } from 'lucide-react-native/icons/external-link';
export { default as Phone } from 'lucide-react-native/icons/phone';
export { default as ShoppingCart } from 'lucide-react-native/icons/shopping-cart';
export { default as TrainFront } from 'lucide-react-native/icons/train-front';
export { default as UtensilsCrossed } from 'lucide-react-native/icons/utensils-crossed';
export { default as Wrench } from 'lucide-react-native/icons/wrench';
export { default as Zap } from 'lucide-react-native/icons/zap';
// In-ride maquette conformity (#1094): disclaimer banner + detour badge.
export { default as CornerUpLeft } from 'lucide-react-native/icons/corner-up-left';
export { default as Info } from 'lucide-react-native/icons/info';
// Shared read-only trip view (#1177): read-only banner marker.
export { default as Eye } from 'lucide-react-native/icons/eye';
// Roadbook undo/redo (#1178): ↶ Annuler / ↷ Rétablir menu items.
export { default as Undo2 } from 'lucide-react-native/icons/undo-2';
export { default as Redo2 } from 'lucide-react-native/icons/redo-2';
// Account row leading icons (#1214, 10-account maquette): language + theme.
export { default as Globe } from 'lucide-react-native/icons/globe';
export { default as Palette } from 'lucide-react-native/icons/palette';
