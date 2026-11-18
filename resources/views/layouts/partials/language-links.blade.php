{{-- The three language links (flags), used by the sidebar and the guest pages. --}}
<!-- Steag Anglia (EN) -->
<a href="{{ route('lang.switch', 'en') }}" class="dashboard-language-link btn btn-sm p-1 @if(app()->getLocale() == 'en') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="English" aria-label="English" hreflang="en" lang="en" @if(app()->getLocale() == 'en') aria-current="true" @endif>
    <svg class="dashboard-language-flag" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 300" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
        <rect width="600" height="300" fill="#012169"/>
        <path d="M0,0 L600,300 M600,0 L0,300" stroke="#fff" stroke-width="60"/>
        <path d="M0,0 L600,300 M600,0 L0,300" stroke="#C8102E" stroke-width="40"/>
        <path d="M300,0 V300 M0,150 H600" stroke="#fff" stroke-width="100"/>
        <path d="M300,0 V300 M0,150 H600" stroke="#C8102E" stroke-width="60"/>
    </svg>
</a>
<!-- Steag România (RO) -->
<a href="{{ route('lang.switch', 'ro') }}" class="dashboard-language-link btn btn-sm p-1 @if(app()->getLocale() == 'ro') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="Română" aria-label="Română" hreflang="ro" lang="ro" @if(app()->getLocale() == 'ro') aria-current="true" @endif>
    {{-- Blue | yellow | red: each rect covers the stripes to its right. --}}
    <svg class="dashboard-language-flag" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
        <rect width="3" height="2" fill="#CE1126"/>
        <rect width="2" height="2" fill="#FCD116"/>
        <rect width="1" height="2" fill="#002B7F"/>
    </svg>
</a>
<!-- Steag Italia (IT) -->
<a href="{{ route('lang.switch', 'it') }}" class="dashboard-language-link btn btn-sm p-1 @if(app()->getLocale() == 'it') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="Italiano" aria-label="Italiano" hreflang="it" lang="it" @if(app()->getLocale() == 'it') aria-current="true" @endif>
    <svg class="dashboard-language-flag" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
        <rect width="3" height="2" fill="#CE1126"/>
        <rect width="2" height="2" fill="#FFFFFF"/>
        <rect width="1" height="2" fill="#009246"/>
    </svg>
</a>
