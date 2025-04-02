/**
 * Analytics Tracker Script
 * This script should be included on client websites for tracking
 */
(function() {
    // Configuration
    const API_URL = 'http://analitics.videobaza.ua/api.php';
    
    // Get tracking code from script tag
    const scriptTag = document.currentScript || (function() {
        const scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();
    
    const urlParams = new URLSearchParams(scriptTag.src.split('?')[1]);
    const trackingCode = urlParams.get('code');
    
    if (!trackingCode) {
        console.error('Analytics tracker: Missing tracking code');
        return;
    }
    
    // Utility functions
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }
    
    function setCookie(name, value, days) {
        let expires = '';
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = `; expires=${date.toUTCString()}`;
        }
        document.cookie = `${name}=${value}${expires}; path=/`;
    }
    
    function getVisitorId() {
        let visitorId = getCookie('_analytics_visitor');
        if (!visitorId) {
            visitorId = 'v' + Math.random().toString(36).substring(2) + Date.now();
            setCookie('_analytics_visitor', visitorId, 365);
        }
        return visitorId;
    }
    
    function getDeviceInfo() {
        const userAgent = navigator.userAgent;
        let device = 'Desktop';
        let operatingSystem = 'Unknown';
        let browser = 'Unknown';
        
        // Detect device type
        if (/Mobi|Android|iPhone|iPad|iPod/i.test(userAgent)) {
            device = 'Mobile';
            if (/iPad|Tablet/i.test(userAgent)) {
                device = 'Tablet';
            }
        }
        
        // Detect operating system
        if (/Windows/i.test(userAgent)) {
            operatingSystem = 'Windows';
        } else if (/Macintosh|Mac OS/i.test(userAgent)) {
            operatingSystem = 'MacOS';
        } else if (/Android/i.test(userAgent)) {
            operatingSystem = 'Android';
        } else if (/iOS|iPhone|iPad|iPod/i.test(userAgent)) {
            operatingSystem = 'iOS';
        } else if (/Linux/i.test(userAgent)) {
            operatingSystem = 'Linux';
        }
        
        // Detect browser
        if (/Firefox/i.test(userAgent)) {
            browser = 'Firefox';
        } else if (/Chrome/i.test(userAgent) && !/Chromium|Edge/i.test(userAgent)) {
            browser = 'Chrome';
        } else if (/Safari/i.test(userAgent) && !/Chrome|Chromium|Edge/i.test(userAgent)) {
            browser = 'Safari';
        } else if (/Edge/i.test(userAgent)) {
            browser = 'Edge';
        } else if (/MSIE|Trident/i.test(userAgent)) {
            browser = 'Internet Explorer';
        }
        
        return {
            device,
            operating_system: operatingSystem,
            browser
        };
    }
    
    function getUTMParameters() {
        const urlParams = new URLSearchParams(window.location.search);
        return {
            utm_source: urlParams.get('utm_source'),
            utm_medium: urlParams.get('utm_medium'),
            utm_campaign: urlParams.get('utm_campaign'),
            utm_term: urlParams.get('utm_term'),
            utm_content: urlParams.get('utm_content')
        };
    }
    
    function getReferrer() {
        const referrer = document.referrer;
        if (!referrer) return null;
        
        try {
            const referrerDomain = new URL(referrer).hostname;
            const currentDomain = window.location.hostname;
            
            // If referrer is from the same domain, it's an internal referral
            if (referrerDomain === currentDomain) {
                return null;
            }
            
            return referrer;
        } catch (e) {
            return null;
        }
    }
    
    // Track page view
    function trackPageView() {
        const visitorId = getVisitorId();
        const deviceInfo = getDeviceInfo();
        const utmParams = getUTMParameters();
        const referrer = getReferrer();
        
        const data = {
            tracking_code: trackingCode,
            visitor_id: visitorId,
            event_type: 'pageview',
            page_url: window.location.href,
            page_title: document.title,
            referrer: referrer,
            ...deviceInfo,
            ...utmParams
        };
        
        sendData(data);
        
        // Track time on page
        trackTimeOnPage();
    }
    
    // Track session duration
    let sessionStart = Date.now();
    let isFirstVisit = true;
    
    function trackSession() {
        const visitorId = getVisitorId();
        const sessionDuration = Math.floor((Date.now() - sessionStart) / 1000);
        
        if (isFirstVisit) {
            isFirstVisit = false;
            return;
        }
        
        const data = {
            tracking_code: trackingCode,
            visitor_id: visitorId,
            event_type: 'session_update',
            session_duration: sessionDuration
        };
        
        sendData(data);
    }
    
    // Track time on page
    let pageLoadTime = Date.now();
    let pageTitle = document.title;
    let pageUrl = window.location.href;
    
    function trackTimeOnPage() {
        let lastActiveTime = Date.now();
        let isActive = true;
        
        // Detect page visibility change
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                isActive = false;
                recordTimeOnPage();
            } else {
                isActive = true;
                pageLoadTime = Date.now();
            }
        });
        
        // Track user activity
        ['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(function(event) {
            document.addEventListener(event, function() {
                lastActiveTime = Date.now();
                isActive = true;
            }, true);
        });
        
        // Check user activity every 30 seconds
        setInterval(function() {
            if (isActive && Date.now() - lastActiveTime > 60000) {
                isActive = false;
                recordTimeOnPage();
            }
        }, 30000);
        
        // Record time on page when leaving
        window.addEventListener('beforeunload', function() {
            recordTimeOnPage();
        });
        
        // Handle page navigation for SPAs
        function handleLocationChange() {
            if (pageUrl !== window.location.href) {
                recordTimeOnPage();
                pageUrl = window.location.href;
                pageTitle = document.title;
                pageLoadTime = Date.now();
                
                // Send new page view
                trackPageView();
            }
        }
        
        // Poll for location changes (for SPAs)
        setInterval(handleLocationChange, 500);
    }
    
    function recordTimeOnPage() {
        const timeOnPage = Math.floor((Date.now() - pageLoadTime) / 1000);
        if (timeOnPage < 1) return;
        
        const visitorId = getVisitorId();
        
        const data = {
            tracking_code: trackingCode,
            visitor_id: visitorId,
            event_type: 'pageview',
            page_url: pageUrl,
            page_title: pageTitle,
            time_on_page: timeOnPage
        };
        
        sendData(data);
        
        // Reset timer
        pageLoadTime = Date.now();
    }
    
    // Send data to API
    function sendData(data) {
        if (navigator.sendBeacon) {
            // Use SendBeacon API if available (works better when page is unloading)
            navigator.sendBeacon(API_URL, JSON.stringify(data));
        } else {
            // Fallback to fetch API
            fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                keepalive: true
            }).catch(function(error) {
                console.error('Analytics tracker: Failed to send data', error);
            });
        }
    }
    
    // Initialization
    function init() {
        // Track page view when DOM is ready
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(trackPageView, 1000);
        } else {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(trackPageView, 1000);
            });
        }
        
        // Track session duration when user leaves
        window.addEventListener('beforeunload', trackSession);
        
        // Also periodically track session duration for long sessions
        setInterval(trackSession, 60000); // Every minute
    }
    
    // Start tracking
    init();
})();