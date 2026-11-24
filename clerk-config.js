// ------------------------------------------------------------
// Clerk setup: your Publishable Key (starts with pk_test_ or pk_live_).
// Find it in the Clerk Dashboard -> API keys. Never paste a Secret Key here.
// ------------------------------------------------------------
window.CLERK_PUBLISHABLE_KEY = 'pk_live_Y2xlcmsuamFzaGR1Y2tzaW5hcm93LmNvbSQ';

// Where the website talks to the database (the PHP files in the "api" folder).
window.DUCKS_API = 'api/index.php';

// Where people land after signing in / signing up / signing out.
window.DUCKS_ROUTES = {
  signIn: 'signin.html',
  signUp: 'signup.html',
  afterAuth: 'dashboard.html',
  afterSignUp: 'onboarding.html',
  onboarding: 'onboarding.html',
  afterSignOut: 'index.html'
};
