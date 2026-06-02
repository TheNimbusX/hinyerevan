import L from 'leaflet'
import iconUrl from 'leaflet/dist/images/marker-icon.png'
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png'
import shadowUrl from 'leaflet/dist/images/marker-shadow.png'
import { initMapMarkerIcons } from './mapMarkerIcons'

let ready = false

/** Fix Vite broken default pin paths; preload direction pin SVGs for maps. */
export function setupLeaflet() {
  if (ready) return
  ready = true

  delete L.Icon.Default.prototype._getIconUrl
  L.Icon.Default.mergeOptions({
    iconUrl,
    iconRetinaUrl,
    shadowUrl,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41],
  })

  initMapMarkerIcons()
}
