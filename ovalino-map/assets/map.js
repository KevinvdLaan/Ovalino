(function() {
    'use strict';

    if (typeof L === 'undefined') { console.error('Leaflet library not loaded'); return; }

var map = null;
var popupOpen = false;
var markerStore = {}; 

var clusters = L.markerClusterGroup({
    maxClusterRadius: 60,
    disableClusteringAtZoom: 16,
    spiderfyOnMaxZoom: true,
    iconCreateFunction: function(cluster) {
        var count = cluster.getChildCount();
        var size = 'small';
        var radius = 20;
        if (count > 10) {
            size = 'medium';
            radius = 25;
        }
        if (count > 50) {
            size = 'large';
            radius = 30;
        }
        return L.divIcon({
            html: '<div style="width:' + (radius * 2) + 'px;height:' + (radius * 2) + 'px;background-color:#861121;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:12px;border:3px solid white;box-shadow:0 4px 8px rgba(0,0,0,0.2);">' +
                count + '</div>',
            iconSize: [radius * 2, radius * 2],
            className: 'ovrp-marker-cluster-' + size,
        });
    },
});

var config = window.ovrepMapConfig || {};
var ajaxUrl = config.ajaxUrl || '/wp-admin/admin-ajax.php';
var scheduleBaseUrl = config.scheduleBaseUrl || '/';

function initMap() {
    var mapContainer = document.getElementById('ovrp-map');
    if (!mapContainer) {
        console.error('Map container not found');
        return;
    }

    map = L.map(mapContainer).setView([config.centerLat, config.centerLon], config.defaultZoom || 9);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors | <a href="https://www.ovnieuwsuitgroningen.nl/ovalino" target="_blank">Ovalino</a>',
        maxZoom: 19,
    }).addTo(map);

    clusters.addTo(map);

    map.on('popupopen', function () { popupOpen = true; });
    map.on('popupclose', function () { popupOpen = false; });

    var loadDebounceTimer = null;
    var lastBoundsStr = '';

    function onMapChange() {
        if (loadDebounceTimer) clearTimeout(loadDebounceTimer);
        loadDebounceTimer = setTimeout(function() {
            var bounds = map.getBounds();
            var boundsStr = bounds.getNorth() + ',' + bounds.getSouth() + ',' + bounds.getEast() + ',' + bounds.getWest() + ',' + map.getZoom();
            if (boundsStr === lastBoundsStr) return;
            lastBoundsStr = boundsStr;
            loadStops(bounds);
        }, 250);
    }

    map.on('moveend', function () {
        if (popupOpen) return;
        onMapChange();
    });

    onMapChange();

    // Geolocation: Bied gebruikers de mogelijkheid hun huidige locatie te gebruiken
    if (navigator.geolocation) {
        L.Control.extend({
            includes: L.Mixin.Events,
            options: {
                position: 'topleft',
                strings: {
                    title: 'Toon mijn locatie'
                }
            },
            onAdd: function (map) {
                var className = 'leaflet-control-locate',
                    container = L.DomUtil.create('div', className + ' leaflet-bar leaflet-control');

                var link = L.DomUtil.create('a', className + '-button', container);
                link.href = '#';
                link.title = this.options.strings.title;
                link.innerHTML = '📍';
                link.style.fontSize = '18px';
                link.style.display = 'flex';
                link.style.alignItems = 'center';
                link.style.justifyContent = 'center';
                link.style.width = '36px';
                link.style.height = '36px';
                link.style.color = '#861121';
                link.style.textDecoration = 'none';

                L.DomEvent.on(link, 'click', L.DomEvent.preventDefault);
                L.DomEvent.on(link, 'click', function() {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        var lat = position.coords.latitude;
                        var lon = position.coords.longitude;
                        map.setView([lat, lon], 14);
                        L.marker([lat, lon]).addTo(map).bindPopup('Uw huidige locatie').openPopup();
                    }, function(error) {
                        alert('Locatie kon niet worden bepaald: ' + error.message);
                    });
                });

                return container;
            }
        });

        var locateControl = new (L.Control.extend({
            includes: L.Mixin.Events,
            options: {
                position: 'topleft'
            },
            onAdd: function (map) {
                var className = 'leaflet-control-locate',
                    container = L.DomUtil.create('div', className + ' leaflet-bar leaflet-control');

                var link = L.DomUtil.create('a', className + '-button', container);
                link.href = '#';
                link.title = 'Toon mijn locatie';
                link.innerHTML = '📍';
                link.style.fontSize = '18px';
                link.style.display = 'flex';
                link.style.alignItems = 'center';
                link.style.justifyContent = 'center';
                link.style.width = '36px';
                link.style.height = '36px';
                link.style.color = '#861121';
                link.style.textDecoration = 'none';
                link.style.cursor = 'pointer';

                L.DomEvent.on(link, 'click', L.DomEvent.preventDefault);
                L.DomEvent.on(link, 'click', function() {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        var lat = position.coords.latitude;
                        var lon = position.coords.longitude;
                        map.setView([lat, lon], 14);
                        L.marker([lat, lon]).addTo(map).bindPopup('Uw huidige locatie').openPopup();
                    }, function(error) {
                        alert('Locatie kon niet worden bepaald: ' + error.message);
                    });
                });

                return container;
            }
        }))();
        
        locateControl.addTo(map);
    }
}

function loadStops(bounds) {
    var params = new URLSearchParams({
        action: 'ovrp_get_stops',
        north: bounds.getNorth(),
        south: bounds.getSouth(),
        east: bounds.getEast(),
        west: bounds.getWest(),
        zoom: map.getZoom()
    });

    fetch(ajaxUrl + '?' + params.toString())
        .then(function(res) { return res.json(); })
        .then(function(response) {
            if (response.success && Array.isArray(response.data)) {
                renderStops(response.data);
            }
        })
        .catch(function(err) { console.error('Error fetching stops:', err); });
}

function renderStops(stops) {
    var currentZoom = map.getZoom();
    var newMarkerStore = {};
    var toAdd = [];
    var hiddenLines = window.ovrepMapConfig.hiddenLines || [];

    stops.forEach(function(stop) {
        var hasVisibleLine = (stop.type === 'train');
        if (stop.lines && stop.lines.length > 0) {
            for (var i = 0; i < stop.lines.length; i++) {
                var lineRef = stop.lines[i].lineRef;
                if (hiddenLines.indexOf(lineRef) === -1) {
                    hasVisibleLine = true;
                    break;
                }
            }
        }

        if (!hasVisibleLine) {
            return;
        }

        var key = stop.type + '_' + stop.code;
        if (markerStore[key]) {
            // FIX: Update de popup met de nieuwste binnengehaalde vertrektijden
            markerStore[key].bindPopup(createPopupContent(stop));
            
            newMarkerStore[key] = markerStore[key];
            delete markerStore[key];
        } else {
            var marker = (currentZoom >= 13) ? createDetailedMarker(stop) : createSimpleMarker(stop);
            if (marker) {
                newMarkerStore[key] = marker;
                toAdd.push(marker);
            }
        }
    });

    Object.keys(markerStore).forEach(function(key) {
        clusters.removeLayer(markerStore[key]);
    });

    if (toAdd.length > 0) {
        clusters.addLayers(toAdd);
    }

    markerStore = newMarkerStore;
}

function createSimpleMarker(stop) {
    var symbol = stop.type === 'train' ? '🚂' : '🚌';
    var color = '#861121';

    var icon = L.divIcon({
        html: '<div style="width:32px;height:32px;background-color:' + color + ';border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:18px;border:2px solid white;box-shadow:0 4px 8px rgba(0,0,0,0.3);">' +
            symbol + '</div>',
        iconSize: [32, 32],
        className: 'ovrp-marker-icon',
    });
    var marker = L.marker([stop.lat, stop.lon], { icon: icon });
    marker.bindPopup(createPopupContent(stop));
    return marker;
}

function createDetailedMarker(stop) {
    var color = '#861121';

    var marker = L.circleMarker([stop.lat, stop.lon], {
        radius: stop.type === 'train' ? 10 : 7,
        fillColor: color,
        color: '#fff',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.9,
    });
    marker.bindPopup(createPopupContent(stop));
    
    var displayName = stop.name || '';
    marker.bindTooltip(displayName, {
        permanent: false,
        direction: 'top',
        offset: [0, -15],
    });
    return marker;
}

function createPopupContent(stop) {
    var platformVal = '';
    
    // stop.platform bevat nu al "Perron: B5" (met prefix)
    if (stop.platform) {
        platformVal = stop.platform.toString().trim();
    }

    // Perron HTML nu direkt onder de haltenaam
    var platformHtml = '';
    if (platformVal) {
        platformHtml = '<div style="color:#707070 !important; font-size:12px !important; margin-top:2px !important; margin-bottom:8px !important; font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif !important; font-weight:500 !important; display:block !important;">' + escapeHtml(platformVal) + '</div>';
    }

    var lineMap = {};
    
    // Verwerk lijnen die direct aan de halte gekoppeld zijn
    (stop.lines || []).forEach(function(line) {
        var nameStr = (line.name || '').trim();
        if (nameStr) {
            lineMap[nameStr] = {
                name: nameStr,
                colour: line.colour,
                textColour: line.textColour,
                lineRef: line.lineRef || nameStr,
                direction: line.direction || 'outbound'
            };
        }
    });

    // Verwerk lijnen uit de ritten / vertrektijden (zorgt dat missende lijnen altijd getoond worden)
    (stop.departures || []).forEach(function(dep) {
        var nameStr = (dep.line || '').trim();
        if (nameStr && !lineMap[nameStr]) {
            lineMap[nameStr] = {
                name: nameStr,
                colour: '#861121', 
                textColour: '#ffffff',
                lineRef: dep.lineRef || nameStr,
                direction: dep.direction || 'outbound'
            };
        }
    });

    var sortedLines = Object.keys(lineMap).map(function(key) {
        return lineMap[key];
    }).sort(function(a, b) {
        return (a.name || '').localeCompare(b.name || '', undefined, { numeric: true, sensitivity: 'base' });
    });

    // Gekleurde line badges
    var lineBadges = sortedLines.length > 0
        ? sortedLines.map(function(line) {
            var label = escapeHtml(line.name || '');
            
            var bg = (line.colour || '').trim();
            if (bg === '') {
                bg = '#861121';
            } else if (bg.indexOf('#') !== 0) {
                bg = '#' + bg;
            }

            var fg = (line.textColour || '').trim();
            if (fg === '') {
                fg = '#ffffff';
            } else if (fg.indexOf('#') !== 0) {
                fg = '#' + fg;
            }

            var ref = line.lineRef || line.name || '';
            var today = new Date().toISOString().split('T')[0];
            var direction = line.direction || 'outbound';
            var url = scheduleBaseUrl + '/dienstregeling/?ovld_direction=' + encodeURIComponent(direction) + '&ovld_line=' + encodeURIComponent(ref) + '&ovld_variant=' + today;

            var finalFg = fg;
            if (fg === '#ffffff' || fg === '#fff') {
                if (bg.length === 7) {
                    var c = bg.substring(1);
                    var r = parseInt(c.substring(0, 2), 16) || 0;
                    var g = parseInt(c.substring(2, 4), 16) || 0;
                    var b = parseInt(c.substring(4, 6), 16) || 0;
                    var brightness = (r * 299 + g * 587 + b * 114) / 1000;
                    if (brightness > 150) {
                        finalFg = '#000000';
                    }
                }
            }

            return '<a href="' + escapeHtml(url) + '" target="_blank" style="text-decoration:none;">' +
                '<span style="display:inline-block;margin:0 4px 4px 0;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;background-color:' + escapeHtml(bg) + ';color:' + escapeHtml(finalFg) + ';min-width:28px;text-align:center;">' + label + '</span></a>';
        }).join('')
        : '';

    var linesHtml = lineBadges
        ? '<div style="margin-top:8px;font-size:12px;line-height:1.4;"><strong>Lijnen:</strong><div style="margin-top:4px;">' + lineBadges + '</div></div>'
        : '';

    var trainsHtml = '';
    if (stop.type === 'train' && stop.trains && stop.trains.length > 0) {
        var sortedTrains = stop.trains.slice().sort(function(a, b) {
            return (a.label || '').localeCompare(b.label || '', undefined, { numeric: true });
        });
        var trainItems = sortedTrains.map(function(train) {
            var url = scheduleBaseUrl + '/treindienstregeling/?direction=' + encodeURIComponent(train.ref);
            return '<div style="margin-top:4px;"><a href="' + escapeHtml(url) + '" target="_blank" style="color:#861121;text-decoration:none;font-weight:bold;font-size:12px;">🚆 ' + escapeHtml(train.label) + '</a></div>';
        }).join('');
        trainsHtml = '<div style="margin-top:10px;font-size:12px;line-height:1.4;"><strong>Treinen op dit station:</strong>' + trainItems + '</div>';
    }

    var departuresHtml = '';
    function formatDepartureTime(dep) {
        var time = escapeHtml(dep.time || '');
        if (dep.is_cancelled) {
            return '<span style="color:#d00;text-decoration:line-through;">' + time + '</span> <span style="color:#d00;font-weight:700;">(vervallen)</span>';
        }
        var delay = Number(dep.delay_seconds || 0);
        if (delay === 0) {
            return time;
        }
        var minutes = Math.ceil(Math.abs(delay) / 60);
        var sign = delay > 0 ? '+' : '-';
        var color = delay > 0 ? '#d00' : '#0a0';
        return time + ' <span style="color:' + color + ';">' + sign + minutes + '</span>';
    }

    if (stop.departures && stop.departures.length > 0) {
        var depItems = stop.departures.map(function(dep) {
            var lineLabel = escapeHtml(dep.line || '');
            var destLabel = escapeHtml(dep.destination || '');
            var timeLabel = formatDepartureTime(dep);
            var url = '';

            if (stop.type === 'bus' && dep.lineRef) {
                var today = new Date().toISOString().split('T')[0];
                var direction = dep.direction || 'outbound';
                url = scheduleBaseUrl + '/dienstregeling/?ovld_direction=' + encodeURIComponent(direction) + '&ovld_line=' + encodeURIComponent(dep.lineRef) + '&ovld_variant=' + today;
            } else if (stop.type === 'train' && dep.direction) {
                url = scheduleBaseUrl + '/treindienstregeling/?direction=' + encodeURIComponent(dep.direction);
            }

            if (url) {
                return '<div style="margin-top:2px;"><a href="' + escapeHtml(url) + '" target="_blank" style="color:inherit;text-decoration:none;font-size:12px;line-height:1.4;">- ' + lineLabel + ' → ' + destLabel + ' <span style="font-weight:bold;">' + timeLabel + '</span></a></div>';
            }
            return '<div style="margin-top:2px;font-size:12px;line-height:1.4;">- ' + lineLabel + ' → ' + destLabel + ' <span style="font-weight:bold;">' + timeLabel + '</span></div>';
        }).join('');

        if (depItems) {
            departuresHtml = '<div style="margin-top:12px;font-size:12px;line-height:1.4;"><strong>Volgende geplande ritten:</strong><div style="margin-top:4px;">' + depItems + '</div></div>';
        }
    }

    var departuresUrlHtml = '';
    if (stop.departures_url) {
        departuresUrlHtml = '<div style="margin-top:12px;"><a href="' + escapeHtml(stop.departures_url) + '" target="_blank" style="color:#861121;text-decoration:underline;font-weight:bold;font-size:12px;font-family:\'circularstd-bold\', sans-serif;">Actuele vertrektijden</a></div>';
    }

    return '<div style="font-size:14px;min-width:220px;font-family:\'circularstd-bold\', sans-serif;">' +
            '<strong style="color:#861121;font-size:14px;font-family:\'circularstd-bold\', sans-serif;display:block;margin-bottom:2px;">' + escapeHtml(stop.name) + '</strong>' +
            platformHtml +
            linesHtml +
            trainsHtml +
            departuresHtml +
            departuresUrlHtml +
            '</div>';
}

function escapeHtml(text) {
    if (typeof text !== 'string') return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMap);
} else {
    initMap();
}

window.ovrepMap = {
    getMap: function() { return map; },
    loadStops: loadStops,
    refresh: function() { return map && map.invalidateSize() && map.getBounds() && loadStops(map.getBounds()); }
};

})();