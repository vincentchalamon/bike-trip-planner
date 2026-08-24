// Icon parity with the web (lucide). Re-export the curated set the mobile
// screens use so call sites import from one place. Add icons here as needed.
// TODO(perf #1194): the barrel re-export pulls the whole `lucide-react-native`
// icon set (~3300 icons) into the bundle; deep imports would shrink it, but
// they are not a mechanical rewrite — many names here are legacy aliases
// resolved through the package's alias map, not the file each icon actually
// lives in (e.g. `AlertTriangle` -> `dist/esm/icons/triangle-alert.js`,
// `HelpCircle` -> `dist/esm/icons/circle-question-mark.js`, `MoreVertical` ->
// `dist/esm/icons/ellipsis-vertical.js`), and that mapping is an internal,
// version-fragile implementation detail (checked against v0.545.0), not a
// published contract. Left alone for now; revisit if bundle size becomes a
// measured problem, ideally with a codemod driven off the package's own
// alias table rather than a hand-maintained one.
export {
  AlertTriangle,
  ArrowLeft,
  Bike,
  Calendar,
  Check,
  ChevronRight,
  CloudRain,
  Coffee,
  Copy,
  Download,
  FileUp,
  Inbox,
  Map,
  MapPin,
  Menu,
  Mountain,
  Pencil,
  Plus,
  RefreshCw,
  Route,
  Search,
  Settings,
  Tent,
  Trash2,
  X,
} from 'lucide-react-native';
// Share feature icon (#1048) — appended so the sorted set above stays untouched.
export { Share2 } from 'lucide-react-native';
// Spike-UX restyle: icons used by the redesigned screens (login/create/roadbook/
// share/map/stage-detail). Appended to keep the sorted set above stable.
export {
  CloudSun,
  FileText,
  Flag,
  Gauge,
  Image as ImageIcon,
  KeyRound,
  Link2,
  Lock,
  Mail,
  Minus,
  MoreVertical,
} from 'lucide-react-native';
// Resupply block (#1105): sectioned food/water suggestions.
export { ShoppingBag } from 'lucide-react-native';
// Notifications screen (#1120): permission banner + per-category icons.
export { Bell, BellOff, CheckCircle2 } from 'lucide-react-native';
// Offline map degradation (#1148): discrete "offline map" indicator.
export { CloudOff } from 'lucide-react-native';
// In-ride mode (#1149): help bubble, offline badge, GPS position.
export { HelpCircle, Navigation, WifiOff } from 'lucide-react-native';
// In-ride nearby-pois (#1150): the 8 intent chips + POI card affordances
// (Search/Tent/AlertTriangle already exported above).
export {
  Clock,
  Cross,
  Droplet,
  ExternalLink,
  Phone,
  ShoppingCart,
  TrainFront,
  UtensilsCrossed,
  Wrench,
  Zap,
} from 'lucide-react-native';
// In-ride maquette conformity (#1094): disclaimer banner + detour badge.
export { CornerUpLeft, Info } from 'lucide-react-native';
