/** The hidden #current_location input whose data-lat/data-lon hold the location the page works with. */

export function createInputLocationElement(create) {
    if(create) {
        if (document.getElementById('current_location')) return;

        let currentLocation = document.createElement('input');
        currentLocation.setAttribute('id', 'current_location');
        currentLocation.classList.add('d-none');
        document.body.appendChild(currentLocation);        
    }
    else {
        let cl = document.getElementById('current_location');
        if(cl) {
            cl.remove();
        }
    }
}
