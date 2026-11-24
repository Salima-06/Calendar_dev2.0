// =====================================================================
// Dashboard greetings. EDIT THIS FILE to add or change greetings. No other code needs to change.
//
// How it works
//   - Each time someone signs in, the dashboard picks ONE random greeting for that moment.
//   - If it is early morning (before 8:00) or late night (22:00 or later) and there are greetings
//     in that list, it uses those. Otherwise it uses the list for today's weekday.
//   - If the list it needs is empty, it uses "fallback", so nothing ever looks blank.
//   - Times and weekdays use the person's own time zone (from onboarding).
//
// The person's first name is added in gold after the greeting. To control exactly where the name
// goes, type {name} inside the text, for example:  "Good to see you, {name}!"
//
// To add more: put a comma, then a new greeting in quotes, inside the right list.
// Empty lists ( [] ) are fine: they just use the fallback until you fill them in.
// =====================================================================
window.DUCKS_GREETINGS = {
  sunday: [
    "Happy Sunday :D",
    "Enjoy your weekend while it lasts...",
    "Getting ready for the week?"

  ],
  monday: [
    "Woo! Good start to the week?",
    "It smells like a good week is coming...",
    "A bright week on the horizon?"
    
  ],
  tuesday: [
    "Only three more days til Friday...",
    "Second day of the week! How we feelin'?",
    "Bruno Mars was born on Tuesday, isn't that exciting?"
  ],
  wednesday: [
    "Hump day, or rather... Duck day!",
    "Only two more days til Friday...",
    "What a good day for a hackathon!"
  ],
  thursday: [
    "It's Thursday, I think...?",
    "How's your week been?",
    "Almost Friday!"
  ],
  friday: [
    "It's Friday, Friday, gotta quack down on Friday.",
    "Thank God it's Friday",
    "It has certainly been a week, but we survived.",
  ],
  saturday: [
    "Don't forget to take a break every once in a while.",
    "Enjoying the weekend so far?",
    "What a nice day to go feed the ducks :D"

  ],

  earlyMorning: [
    "Early duck gets the worm.",
    "Hope you have a great day ahead.",
    "Wow, someone's up early",
    "Getting ahead of the flock, aren't we."

    
  ],
  lateNight: [         // 22:00 and later
    "Wow! you're up late! Need a late night quack?",
    "Night owl? More like Nocturnal waterfowl.",
    "You will go to sleep eventually...right?"

  ],

  fallback: [          // used whenever the list above is empty
    "Hello",
    "Welcome back",
    "Good to see you"
  ]
};

// Hours that count as early morning / late night (24-hour clock).
window.DUCKS_GREETING_HOURS = { earlyMorningBefore: 8, lateNightFrom: 22 };
