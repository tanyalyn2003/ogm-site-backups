(function () {
  const widget = document.querySelector(".chat-widget");
  if (!widget) return;

  const toggle = widget.querySelector(".chat-toggle");
  const panel = widget.querySelector(".chat-panel");
  const closeBtn = widget.querySelector(".chat-close");
  const header = panel.querySelector(".chat-header");
  const messages = widget.querySelector(".chat-messages");
  const form = widget.querySelector(".chat-input");
  const input = form.querySelector('input[name="message"]');
  const submitButton = form.querySelector('button[type="submit"]');
  const MOBILE_CHAT_BREAKPOINT = 640;
  const PARTIAL_LEAD_ENDPOINT = "chat-partial-lead.php";
  const LEAD_UPLOAD_ACCEPT = ".pdf,.jpg,.jpeg,.png,.webp,.gif";
  const LEAD_UPLOAD_ALLOWED_EXTENSIONS = ["pdf", "jpg", "jpeg", "png", "webp", "gif"];
  const LEAD_UPLOAD_MAX_FILES = 4;
  const LEAD_UPLOAD_MAX_FILE_SIZE = 5 * 1024 * 1024;
  const LEAD_UPLOAD_MAX_TOTAL_SIZE = 12 * 1024 * 1024;
  const CHAT_SESSION_ID_KEY = "ogm-chat-session-id";
  const CHAT_SESSION_STATE_KEY = "ogm-chat-session-state-v1";
  const RESET_CHAT_PATTERN = /^(?:start over|restart(?: chat)?|reset(?: chat)?|clear chat|cancel chat|new chat)$/i;
  let preserveMobileChatTimer = 0;
  let partialLeadSaveTimer = 0;
  let pendingReplyTimer = 0;
  let pendingReplyRequestKey = 0;
  let lastPartialLeadSignature = "";
  let isRestoringChatSession = false;

  const resetBtn = document.createElement("button");
  resetBtn.type = "button";
  resetBtn.className = "chat-reset";
  resetBtn.textContent = "Start Over";
  resetBtn.setAttribute("aria-label", "Start chat over");

  if (header) {
    const headerTitle = header.querySelector("span");
    const headerActions = document.createElement("div");
    headerActions.className = "chat-header-actions";

    if (headerTitle) {
      headerTitle.classList.add("chat-header-title");
    }

    headerActions.appendChild(resetBtn);
    headerActions.appendChild(closeBtn);
    header.appendChild(headerActions);
  }

  function isMobileChatViewport() {
    return typeof window !== "undefined" && window.innerWidth <= MOBILE_CHAT_BREAKPOINT;
  }

  function updateChatViewportState() {
    if (typeof window === "undefined") {
      return;
    }

    const visualViewport = window.visualViewport;
    const visibleHeight = visualViewport && visualViewport.height
      ? visualViewport.height
      : window.innerHeight;
    const viewportOffsetTop = visualViewport && typeof visualViewport.offsetTop === "number"
      ? visualViewport.offsetTop
      : 0;
    const keyboardInset = Math.max(
      0,
      Math.round(window.innerHeight - (visibleHeight + viewportOffsetTop))
    );
    const mobileBottom = Math.max(16, keyboardInset + 16);

    widget.style.setProperty("--chat-visible-height", `${Math.max(320, Math.round(visibleHeight))}px`);
    widget.style.setProperty("--chat-mobile-bottom", `${mobileBottom}px`);
    widget.classList.toggle("chat-keyboard-active", isMobileChatViewport() && keyboardInset > 20);
  }

  function preserveMobileChatContext() {
    if (!isMobileChatViewport() || !panel.classList.contains("is-open")) {
      return;
    }

    const activeElement = document.activeElement;
    const isLeadField = activeElement && messages.contains(activeElement);

    if (isLeadField) {
      const bubble = activeElement.closest(".chat-message");
      if (bubble) {
        scrollMessages(bubble, "start");
      }

      if (typeof activeElement.scrollIntoView === "function") {
        try {
          activeElement.scrollIntoView({ block: "center", inline: "nearest" });
        } catch (error) {
          activeElement.scrollIntoView();
        }
      }

      return;
    }

    const lastBubble = messages.lastElementChild;
    if (lastBubble) {
      scrollMessages(lastBubble, "bottom");
    }
  }

  function queuePreserveMobileChatContext(delay) {
    window.clearTimeout(preserveMobileChatTimer);
    preserveMobileChatTimer = window.setTimeout(() => {
      updateChatViewportState();
      preserveMobileChatContext();
    }, delay || 120);
  }

  updateChatViewportState();

  function createChatSessionId() {
    return `chat-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function getChatSessionId() {
    const fallbackId = createChatSessionId();

    try {
      const existingId = window.sessionStorage.getItem(CHAT_SESSION_ID_KEY);
      if (existingId) {
        return existingId;
      }

      window.sessionStorage.setItem(CHAT_SESSION_ID_KEY, fallbackId);
      return fallbackId;
    } catch (error) {
      return fallbackId;
    }
  }

  function storeChatSessionId(sessionId) {
    try {
      window.sessionStorage.setItem(CHAT_SESSION_ID_KEY, sessionId);
    } catch (error) {
      // Session restore should never interrupt the chat experience.
    }
  }

  function clearPersistedChatSessionState() {
    try {
      window.sessionStorage.removeItem(CHAT_SESSION_STATE_KEY);
    } catch (error) {
      // Session restore should never interrupt the chat experience.
    }
  }

  let chatSessionId = getChatSessionId();

  function readChatSessionState() {
    try {
      const raw = window.sessionStorage.getItem(CHAT_SESSION_STATE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (error) {
      return null;
    }
  }

  function writeChatSessionState(state) {
    try {
      window.sessionStorage.setItem(CHAT_SESSION_STATE_KEY, JSON.stringify(state));
    } catch (error) {
      // Session restore should never interrupt the chat experience.
    }
  }

  const knowledgeEntries = Array.isArray(window.OGM_CHAT_KNOWLEDGE && window.OGM_CHAT_KNOWLEDGE.entries)
    ? window.OGM_CHAT_KNOWLEDGE.entries
    : [];

  const BUSINESS_PROFILE = {
    businessName: "Olive Glass & Marble",
    location: "Fayetteville, NC",
    phone: "910-484-5277",
    serviceArea: ["Fayetteville", "Pinehurst", "Southern Pines", "Fort Liberty"],
    faqs: [
      {
        question: "What do you guys do?",
        answer: "We specialize in custom countertops and glass work, including granite, quartz, marble, quartzite, and shower enclosures. Everything is templated, fabricated, and installed by our team."
      },
      {
        question: "Where are you located?",
        answer: "We're based in Fayetteville, NC and serve surrounding areas including Pinehurst, Southern Pines, and Fort Liberty."
      },
      {
        question: "Do you work with homeowners or builders?",
        answer: "Both. We work with homeowners, contractors, and custom builders on everything from single-room projects to full homes."
      },
      {
        question: "How do I get started?",
        answer: "You can request a quote through our website or call us at 910-484-5277. Once we have your details, we'll schedule a measure and guide you through material selection."
      },
      {
        question: "How much do countertops cost?",
        answer: "Pricing depends on the material, layout, and edge details. Quartz and granite are typically more affordable, while marble and quartzite are higher-end options."
      },
      {
        question: "Can I get a rough estimate before measuring?",
        answer: "Yes, we can provide a ballpark estimate based on measurements or plans. Final pricing is confirmed after templating."
      },
      {
        question: "What's included in the price?",
        answer: "Our pricing typically includes material, fabrication, edge profile, sink cutouts, and installation."
      },
      {
        question: "Do you require a deposit?",
        answer: "Yes, a deposit is required to move forward with fabrication after the quote is approved."
      },
      {
        question: "What's the best countertop material?",
        answer: "It depends on your needs. Quartz is low maintenance, granite is very durable, marble is beautiful but requires more care, and quartzite offers a natural stone look with high durability."
      },
      {
        question: "What's the difference between quartz and granite?",
        answer: "Granite is a natural stone with unique patterns and heat resistance. Quartz is engineered, more consistent in color, and doesn't require sealing."
      },
      {
        question: "Does granite need to be sealed?",
        answer: "Yes, granite is porous and should be sealed periodically to protect against staining."
      },
      {
        question: "Is marble a good choice?",
        answer: "Marble is a great choice for certain spaces like bathrooms, but it does require more maintenance and can etch or stain more easily."
      },
      {
        question: "What is the process from start to finish?",
        answer: "The process is quote, measure, template, fabrication, installation. We handle everything in-house."
      },
      {
        question: "What is templating?",
        answer: "Templating is when we take precise measurements of your space to ensure a perfect fit before fabrication."
      },
      {
        question: "How long does the process take?",
        answer: "Typically 1-2 weeks from template to installation, depending on the material and project size."
      },
      {
        question: "Do cabinets need to be installed first?",
        answer: "Yes, cabinets must be fully installed and level before we template."
      },
      {
        question: "How long does installation take?",
        answer: "Most installs are completed in one day."
      },
      {
        question: "Do I need to be home during installation?",
        answer: "We recommend it, but it's not always required as long as access is arranged."
      },
      {
        question: "Will you remove old countertops?",
        answer: "Yes, we can remove existing countertops if needed."
      },
      {
        question: "Do you do shower doors?",
        answer: "Yes, we design and install custom frameless shower enclosures."
      },
      {
        question: "What types of glass do you offer?",
        answer: "We offer custom cut glass and mirrors, along with shower glass options like clear, low-iron, frosted, and privacy glass depending on the look and application."
      },
      {
        question: "What shower enclosure options do you offer?",
        answer: "We offer frameless and semi-frameless shower enclosures, along with fixed panel, hinged door, and sliding configurations based on your layout."
      },
      {
        question: "Do you do bath enclosures?",
        answer: "Yes. We build custom tub and bath enclosures sized for alcoves, combination bath spaces, and remodel layouts where a stock unit will not fit cleanly."
      },
      {
        question: "How long does a shower enclosure take?",
        answer: "Typically about 1-2 weeks after measurement."
      },
      {
        question: "How do I clean my countertops?",
        answer: "Use a mild soap and water or a stone-safe cleaner. Avoid harsh chemicals."
      },
      {
        question: "Can I put hot pans on my countertops?",
        answer: "Granite and quartzite handle heat well, but we always recommend using trivets to protect the surface."
      },
      {
        question: "Will my countertops stain?",
        answer: "Properly sealed natural stone resists staining. Quartz is non-porous and more stain-resistant."
      },
      {
        question: "I don't know what to choose - can you help?",
        answer: "Absolutely. We'll guide you based on your style, budget, and how you use your space to find the best fit."
      },
      {
        question: "What's the most popular option right now?",
        answer: "Quartz is currently the most popular due to its durability and low maintenance."
      },
      {
        question: "What adds the most value to my home?",
        answer: "High-quality quartz or natural stone countertops typically add the most value and appeal."
      },
      {
        question: "Why should I choose you over other companies?",
        answer: "We handle everything in-house, use high-quality materials, and have decades of experience delivering precise, professional installations."
      },
      {
        question: "How soon can you start?",
        answer: "We can usually get you scheduled quickly - reach out and we'll check availability."
      },
      {
        question: "Can I come see slabs in person?",
        answer: "Yes, we encourage it. Seeing slabs in person helps you choose the perfect material."
      },
      {
        question: "I'm ready to move forward - what's next?",
        answer: "Great, let's get your project started. You can request a quote on our website or call us at 910-484-5277."
      },
      {
        question: "Can someone contact me?",
        answer: "Absolutely. Send us your info and we'll reach out to you shortly."
      }
    ]
  };

  const WELCOME_MESSAGE = "Welcome to Olive Glass & Marble. Need help with countertops, shower glass, or getting a quote for your project?";
  const WELCOME_QUICK_REPLIES = [
    "Get a Quote",
    "Countertop Options",
    "Compare Materials",
    "Shower Glass",
    "Project Process",
    "Talk to Someone"
  ];

  const QUICK_REPLY_PROMPTS = {
    "Get a Quote": "I want a quote",
    "Countertop Options": "What countertop options do you offer?",
    "Compare Materials": "Compare Materials",
    "Shower Glass": "I need a shower door",
    "Project Process": "What is the process from start to finish?",
    "Talk to Someone": "Can someone contact me?"
  };

  const QUICK_QUESTIONS = [
    "How do I get started?",
    "Can I get a rough estimate before measuring?",
    "Do you do shower doors?",
    "What areas do you serve?"
  ];

  const CHAT_PLAYBOOK = {
    fallbackResponse: "I'm happy to help. Are you looking for countertops, shower glass, material options, or a quote?",
    handoffResponse: "For exact pricing, scheduling, or project-specific details, the best next step is to request a quote or call us at 910-484-5277.",
    customerTypes: {
      homeowner: {
        triggers: ["my kitchen", "my house", "our bathroom", "remodel", "home"],
        followUpQuestions: [
          "Is this for a remodel or new construction?",
          "Is this for a kitchen, bathroom, or another space?",
          "Would you like help narrowing down material options?"
        ]
      },
      builder_or_contractor: {
        triggers: ["builder", "contractor", "client", "job site", "plans"],
        followUpQuestions: [
          "Is this for one project or multiple?",
          "Do you already have plans or measurements ready?",
          "Are you looking for pricing, scheduling, or both?"
        ]
      }
    },
    serviceQuestions: {
      countertops: [
        "What space is this for?",
        "Do you know what material you want, or would you like help deciding?",
        "Is this a remodel or new construction?",
        "Do you have measurements or plans available?"
      ],
      shower_glass: [
        "Is this for a new build or a remodel?",
        "Do you already have the shower dimensions?",
        "Is the tile complete yet?",
        "Would you like someone to follow up with you about a quote?"
      ],
      custom_glass: [
        "What type of glass project are you working on?",
        "Do you have measurements already?",
        "Is this for a home or commercial space?"
      ]
    },
    nextStepOptions: {
      generic: [
        "I need countertops",
        "I need shower glass"
      ],
      service: [
        "I need countertops",
        "I need shower glass"
      ],
      process: [
        "I want a quote",
        "I need help choosing a material"
      ],
      estimate: [
        "I want a quote",
        "I need help choosing a material"
      ],
      materials: [
        "Low maintenance is my priority",
        "I want natural stone"
      ],
      compare: [
        "Compare Materials",
        "I need help choosing a material"
      ],
      glass: [
        "I want a quote for shower glass",
        "I want a shower enclosure"
      ],
      care: [
        "Compare Materials",
        "I need help choosing a material"
      ],
      contact: [
        "Have someone contact me",
        "I want a quote"
      ]
    },
    keywordFaqs: [
      {
        intent: "generic",
        question: "What services do you offer?",
        keywords: ["what do you do", "services", "offer", "homeowners", "builders"],
        answer: "We specialize in custom countertops and glass work, including granite, quartz, marble, quartzite, custom glass, and frameless shower enclosures. Our team handles templating, fabrication, and installation.",
        followUp: "Would you like help with countertops or shower glass?"
      },
      {
        intent: "process",
        question: "How do I get started?",
        keywords: ["get started", "what do i do first", "how does this work", "what s the process"],
        answer: "You can request a quote through our website or call us at 910-484-5277. Once we have your project details, we'll help with material selection and the next steps.",
        followUp: "Would you like to request a quote?"
      },
      {
        intent: "estimate",
        question: "How much is quartz?",
        keywords: ["price", "pricing", "cost", "estimate", "quote", "how much", "budget"],
        answer: "Pricing can vary quite a bit depending on the material, the size of the job, and the details of the project. If you'd like, we can help you get started with a quote.",
        followUp: "Would you like to request a quote?"
      },
      {
        intent: "materials",
        question: "I need help choosing a material.",
        keywords: ["quartz", "granite", "marble", "quartzite", "best material", "difference", "help me decide"],
        answer: "We offer several countertop materials, and the best fit depends on your style, maintenance preference, and budget.",
        followUp: "Are you looking for low maintenance, natural stone, or a more high-end look?"
      },
      {
        intent: "materials",
        question: "What countertop material is best if I want low maintenance?",
        keywords: ["low maintenance", "easy to maintain", "best low maintenance material"],
        answer: "Quartz is usually the easiest option if low maintenance is the main priority. It gives you a clean, consistent look and does not require sealing.",
        followUp: "Would you like help comparing quartz with granite or quartzite?"
      },
      {
        intent: "materials",
        question: "What countertop options do you offer?",
        keywords: ["countertop options", "countertop materials", "materials do you offer"],
        answer: "We offer granite, quartz, marble, and quartzite countertops, and we can help narrow down the best fit based on the look, maintenance level, and budget you have in mind.",
        followUp: "Would you like help comparing a few materials?"
      },
      {
        intent: "compare",
        question: "Which is easier to maintain, quartz or granite?",
        keywords: ["easier to maintain", "quartz or granite", "low maintenance", "compare materials"],
        answer: "Quartz is usually easier to maintain because it does not need sealing. Granite is very durable too, but it should be sealed periodically.",
        followUp: "Are you leaning toward lower maintenance or a more natural-stone look?"
      },
      {
        intent: "compare",
        question: "Which is better for a kitchen?",
        keywords: ["better for a kitchen", "best for my kitchen", "kitchen material"],
        answer: "Both quartz and granite can work well in a kitchen. Quartz is a great fit if you want lower maintenance, while granite is a strong choice if you want natural stone and more variation.",
        followUp: "Would you like help narrowing it down for your kitchen?"
      },
      {
        intent: "care",
        question: "Is quartz heat resistant?",
        keywords: ["quartz heat resistant", "hot pans", "heat resistant quartz"],
        answer: "Quartz is durable, but using trivets is still the safest choice. Direct heat can damage the surface.",
        followUp: "Are you comparing quartz with another material?"
      },
      {
        intent: "process",
        question: "How long does the process take?",
        keywords: ["process", "how it works", "templating", "installation", "measure", "timeline", "how soon"],
        answer: "Our process typically includes quote, measure, template, fabrication, and installation. Exact timing depends on the material and project details.",
        followUp: "Would you like help getting started?"
      },
      {
        intent: "glass",
        question: "Do you do shower glass?",
        keywords: ["shower door", "shower glass", "frameless shower", "enclosure"],
        answer: "Yes, we design and install custom frameless shower enclosures.",
        followUp: "Would you like a quote for a shower project?"
      },
      {
        intent: "glass",
        question: "What types of glass do you sell?",
        keywords: ["types of glass", "glass types", "what glass do you sell", "what glass do you offer", "custom cut glass"],
        answer: "We offer custom cut glass and mirrors, including float glass, tempered glass, mirror glass, plexiglass, laminated glass, and insulated glass units. For showers, we also offer clear, low-iron, frosted, and privacy glass options depending on the look you want.",
        followUp: "Are you asking about shower glass, mirrors, or custom cut glass?"
      },
      {
        intent: "glass",
        question: "What shower enclosure options do you offer?",
        keywords: ["shower enclosure options", "shower door options", "frameless or semi frameless", "sliding shower door", "fixed panel shower"],
        answer: "We offer frameless and semi-frameless shower enclosures, plus fixed panel, hinged door, and sliding configurations based on your layout and daily use.",
        followUp: "Is this for a shower remodel or a new build?"
      },
      {
        intent: "glass",
        question: "Do you do bath enclosures?",
        keywords: ["bath enclosures", "tub enclosures", "bath enclosure", "tub enclosure"],
        answer: "Yes. We build custom bath and tub enclosures sized for alcoves, combination bath spaces, and remodel layouts where a stock unit will not fit cleanly.",
        followUp: "Is this for a tub enclosure, shower enclosure, or another glass project?"
      },
      {
        intent: "glass",
        question: "What types of shower glass do you offer?",
        keywords: ["shower glass types", "clear shower glass", "frosted shower glass", "privacy shower glass", "low iron shower glass"],
        answer: "For shower projects, we help match the right glass to the design, including clear, low-iron, frosted, and privacy glass options.",
        followUp: "Would you like help with shower glass options or a quote?"
      },
      {
        intent: "estimate",
        question: "I'm just pricing things right now.",
        keywords: ["just pricing", "just shopping", "looking around", "not ready to buy"],
        answer: "No problem at all. We can still help point you in the right direction and give you a better idea of what to expect.",
        followUp: "Would you like help comparing materials or getting a starting quote?"
      },
      {
        intent: "estimate",
        question: "I don't have exact measurements yet.",
        keywords: ["don't have measurements", "no exact measurements", "no plans yet"],
        answer: "That's okay. Basic measurements or plans can often help with a starting estimate.",
        followUp: "Would you like help with a rough quote?"
      },
      {
        intent: "materials",
        question: "I'm not ready yet.",
        keywords: ["not ready yet", "still deciding", "just looking"],
        answer: "That's perfectly fine. We can still help you compare materials and get a better feel for your options.",
        followUp: "Would you like help comparing quartz, granite, marble, or quartzite?"
      },
      {
        intent: "estimate",
        question: "I'm comparing companies right now.",
        keywords: ["comparing companies", "comparing quotes", "shopping different companies", "looking at other companies"],
        answer: "That makes sense. We can still help you compare materials, talk through the process, and give you a clearer idea of what fits your project.",
        followUp: "Would you like help comparing materials or getting a starting quote?"
      },
      {
        intent: "contact",
        question: "Can someone contact me?",
        keywords: ["call me", "contact me", "get in touch", "reach out", "ready", "move forward", "talk to someone"],
        answer: "Absolutely. You can call Olive Glass & Marble at 910-484-5277, or leave your contact information here and someone from our team can follow up.",
        followUp: "What's the best name and phone number to reach you?"
      }
    ],
    flows: [
      {
        key: "quote",
        intent: "estimate",
        triggers: ["i want a quote", "can i get pricing", "how much would my project cost", "i'm ready to move forward", "how soon can you start", "i want to get on the schedule"],
        opening: "Absolutely. I can help get that started.",
        suggestions: ["Countertops project", "Shower glass project", "I need pricing"],
        contextTokens: ["quote", "estimate", "pricing", "project"],
        offerLead: true
      },
      {
        key: "purchase",
        intent: "contact",
        triggers: [
          "i want to purchase",
          "want to purchase",
          "i want countertop",
          "i want countertops",
          "i want kitchen countertop",
          "i want kitchen countertops",
          "i want bathroom countertop",
          "i want bathroom countertops",
          "i want a shower",
          "i want shower",
          "i want shower glass",
          "i want a shower glass",
          "i want shower doors",
          "i want a shower door",
          "i want a shower enclosure",
          "i want shower enclosure",
          "i want a bath enclosure",
          "i want bath enclosure",
          "i want a tub enclosure",
          "i want tub enclosure",
          "i want custom glass"
        ],
        opening: "Absolutely. We'd love to help with that project.",
        suggestions: ["Let's get started", "Countertops", "Shower Glass"],
        contextTokens: ["purchase", "quote", "project", "get started"],
        offerLead: true,
        startLeadFlow: true
      },
      {
        key: "material-help",
        intent: "materials",
        triggers: ["i'm not sure what material to choose", "what's best for my kitchen", "quartz or granite", "help me decide", "i don't know what i want"],
        opening: "I can help with that. Is your biggest priority low maintenance, natural beauty, durability, or a more high-end look?",
        suggestions: ["Low maintenance is my priority", "I want natural stone", "I want a high-end look"],
        contextTokens: ["materials", "quartz", "granite", "marble", "quartzite"],
        offerLead: false
      },
      {
        key: "countertops",
        intent: "materials",
        triggers: ["i need countertops", "kitchen countertops", "bathroom vanity top", "outdoor kitchen countertop", "replacing countertops"],
        opening: "I can help with that. What space is this for: kitchen, bathroom, outdoor kitchen, or another area?",
        suggestions: ["Kitchen", "Bathroom", "Outdoor kitchen"],
        contextTokens: ["countertops", "kitchen", "bathroom", "outdoor kitchen"],
        offerLead: false
      },
      {
        key: "shower",
        intent: "glass",
        triggers: ["i need a shower door", "do you do frameless shower glass", "i want a shower enclosure"],
        opening: "Yes, we do custom frameless shower enclosures. We'd love to help with your project.",
        suggestions: ["This is for a remodel", "This is for a new build", "I need a quote for shower glass"],
        contextTokens: ["shower", "glass", "enclosure"],
        offerLead: true
      },
      {
        key: "builder-help",
        intent: "estimate",
        triggers: ["i'm a builder", "i am a builder", "i'm a contractor", "i am a contractor", "for my client", "job site", "multiple units"],
        opening: "Happy to help. Is this for one project or multiple, and are you mainly looking for pricing, scheduling, or both?",
        suggestions: ["One project", "Multiple projects", "Pricing and scheduling"],
        contextTokens: ["builder", "contractor", "pricing", "scheduling"],
        offerLead: true
      },
      {
        key: "ready",
        intent: "contact",
        triggers: ["i'm ready", "let's do it", "what's next", "can someone contact me", "can someone call me", "talk to someone"],
        opening: "Absolutely. We'd love to help with your project.",
        suggestions: ["I need a countertop quote", "I need shower glass", "Have someone contact me"],
        contextTokens: ["contact", "quote", "project"],
        offerLead: true
      }
    ]
  };

  const MATERIAL_TERMS = [
    { key: "granite", label: "granite", regex: /\bgranite\b/i },
    { key: "quartz", label: "quartz", regex: /\bquartz\b/i },
    { key: "marble", label: "marble", regex: /\bmarble\b/i },
    { key: "quartzite", label: "quartzite", regex: /\bquartzite\b/i },
    { key: "sintered stone", label: "sintered stone", regex: /\bsintered(?:\s+stone)?\b/i },
    { key: "cultured marble", label: "cultured marble", regex: /\bcultured(?:\s+marble)?\b/i },
    { key: "glass", label: "glass", regex: /\bglass\b/i },
    { key: "mirror", label: "mirrors", regex: /\bmirror(?:s)?\b/i },
    { key: "shower doors", label: "shower doors", regex: /\bshower(?:\s+door|\s+doors|\s+enclosure|\s+enclosures)?\b/i }
  ];

  const MATERIAL_PROFILES = (window.OGM_MATERIAL_COMPARISON && window.OGM_MATERIAL_COMPARISON.materials) || {
    quartz: {
      name: "Quartz",
      durability: "High",
      maintenance: "Low",
      heatResistance: "Moderate",
      appearance: "Consistent color and pattern",
      origin: "Engineered surface",
      bestFor: "Busy kitchens and low-maintenance living",
      notes: "Quartz does not require sealing, but trivets are still recommended."
    },
    granite: {
      name: "Granite",
      durability: "High",
      maintenance: "Moderate",
      heatResistance: "High",
      appearance: "Natural variation and movement",
      origin: "Natural stone",
      bestFor: "Natural stone lovers who want durability",
      notes: "Granite should be sealed periodically."
    },
    marble: {
      name: "Marble",
      durability: "Moderate",
      maintenance: "High",
      heatResistance: "High",
      appearance: "Soft, timeless veining",
      origin: "Natural stone",
      bestFor: "Bathrooms and high-end classic looks",
      notes: "Marble can etch and stain more easily."
    },
    quartzite: {
      name: "Quartzite",
      durability: "High",
      maintenance: "Moderate",
      heatResistance: "High",
      appearance: "Bold natural movement",
      origin: "Natural stone",
      bestFor: "Luxury natural stone looks with durability",
      notes: "Quartzite should be sealed and properly maintained."
    }
  };

  const INTENT_PATTERNS = {
    hours: /\b(hour|hours|open|close|closing|time|schedule)\b/i,
    location: /\b(address|location|located|where|showroom|directions)\b/i,
    phone: /\b(phone|call|number)\b/i,
    email: /\b(email|e-mail)\b/i,
    contact: /\b(contact|reach|talk|speak|follow[\s-]?up)\b/i,
    estimate: /\b(estimate|estimates|quote|quotes|consultation|pricing|price|cost|deposit|budget|ballpark)\b/i,
    service: /\b(service|serve|serving|area|areas|coverage)\b/i,
    process: /\b(process|template|templating|fabrication|installation|install|timeline|how it works)\b/i,
    care: /\b(care|cleaning|clean|maintenance|warranty)\b/i,
    glass: /\b(glass|mirror|mirrors|shower|door|doors|enclosure|enclosures)\b/i,
    compare: /\b(compare|comparison|difference|different|better|best|vs|versus)\b/i,
    yesNo: /^(do|does|did|is|are|can|will|have|has)\b/i
  };

  const QUOTE_READY_PATTERN = /\b(i'?m ready|ready to move forward|let'?s do it|can someone (?:call|contact) me|get on the schedule|how soon can you start|talk to someone|move forward|get started|i want (?:a )?quote|can i get (?:a )?(?:quote|pricing)|request (?:a )?quote|i want to purchase|want to purchase|i want (?:kitchen |bathroom )?counter\s?tops?|i want (?:a )?shower|i want shower glass|i want a shower glass|i want shower doors|i want a shower door|i want a shower enclosure|i want shower enclosure|i want a bath enclosure|i want bath enclosure|i want a tub enclosure|i want tub enclosure|i want custom glass)\b/i;
  const DIRECT_PURCHASE_SERVICE_RULES = [
    {
      serviceKey: "countertops",
      regex: /\b(?:i|we)\s+(?:want|need|am looking for|are looking for)\s+(?:(?:a|an|some|new|replacement)\s+)*(?:(?:kitchen|bathroom|outdoor|vanity)\s+)?(?:counter\s?tops?|vanity\s+tops?)\b/i
    },
    {
      serviceKey: "shower_glass",
      regex: /\b(?:i|we)\s+(?:want|need|am looking for|are looking for)\s+(?:(?:a|an|some|new|frameless)\s+)*(?:shower(?:\s+glass|\s+door|\s+doors|\s+enclosure|\s+enclosures)?|glass\s+enclosures?|bath\s+enclosures?|tub\s+enclosures?)\b/i
    },
    {
      serviceKey: "custom_glass",
      regex: /\b(?:i|we)\s+(?:want|need|am looking for|are looking for)\s+(?:(?:a|an|some|new|custom)\s+)*(?:mirrors?|custom\s+glass|glass\s+partitions?)\b/i
    }
  ];

  const SPACE_TYPE_RULES = [
    { key: "kitchen", label: "kitchen", regex: /\b(kitchen|island|cooktop)\b/i },
    { key: "bathroom", label: "bathroom", regex: /\b(bathroom|bath|vanity|powder room)\b/i },
    { key: "outdoor kitchen", label: "outdoor kitchen", regex: /\b(outdoor kitchen|outdoor)\b/i },
    { key: "shower", label: "shower", regex: /\b(shower|enclosure)\b/i }
  ];

  const MATERIAL_PRIORITY_RULES = [
    { key: "low_maintenance", label: "low maintenance", regex: /\b(low maintenance|easy to maintain|easy upkeep|easy care|easy clean)\b/i },
    { key: "natural_stone", label: "natural stone", regex: /\b(natural stone|real stone|natural look|natural movement)\b/i },
    { key: "high_end_look", label: "a high-end look", regex: /\b(high[ -]?end|luxury|luxurious|upscale|classic look|timeless look)\b/i },
    { key: "durability", label: "durability", regex: /\b(durable|durability|heavy use|busy kitchen|family kitchen)\b/i },
    { key: "consistent_look", label: "a cleaner, more consistent look", regex: /\b(clean look|consistent look|uniform look|consistent pattern)\b/i }
  ];

  const RESEARCH_STAGE_RULES = [
    { key: "quote_ready", regex: QUOTE_READY_PATTERN },
    { key: "researching", regex: /\b(just pricing|just looking|looking around|shopping around|still deciding|not ready|comparing companies|comparing quotes)\b/i },
    { key: "comparing", regex: /\b(compare|comparison|difference|vs|versus|better)\b/i }
  ];

  const FOLLOW_UP_PATTERNS = [
    /^(what about|how about|what else|tell me more|more about|and|also)\b/i,
    /\b(it|that|those|them|there|they|this|these)\b/i
  ];

  const LEAD_INTENT_PATTERN = /\b(estimate|quote|consultation|contact|call me|reach me|follow[\s-]?up|appointment|project|ready|move forward|get started|pricing|how soon|availability|purchase|buy)\b/i;
  const EMAIL_PATTERN = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i;
  const PHONE_PATTERN = /(?:\+?1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}\b/;

  const STOP_WORDS = new Set([
    "a", "an", "and", "are", "as", "at", "be", "by", "can", "do", "for", "from",
    "how", "i", "if", "in", "is", "it", "me", "my", "of", "on", "or", "our",
    "the", "this", "to", "us", "we", "what", "when", "where", "which", "who",
    "with", "you", "your"
  ]);

  const SYNONYM_GROUPS = [
    ["hours", "hour", "open", "opening", "close", "closing", "schedule", "time"],
    ["address", "location", "located", "where", "showroom", "directions"],
    ["contact", "phone", "call", "email", "reach", "number"],
    ["estimate", "estimates", "quote", "quotes", "pricing", "price", "cost", "consultation"],
    ["service", "serve", "serving", "area", "areas", "coverage", "location"],
    ["materials", "material", "stone", "granite", "quartz", "marble", "quartzite", "sintered", "cultured"],
    ["glass", "mirror", "mirrors", "shower", "showers", "door", "doors", "enclosure", "enclosures"],
    ["process", "templating", "template", "fabrication", "installation", "install", "seam", "cnc"],
    ["care", "cleaning", "clean", "maintenance", "warranty"],
    ["sink", "sinks", "undermount", "farmhouse", "lavatory", "vanity"],
    ["difference", "compare", "comparison", "better", "versus", "vs"],
    ["builder", "builders", "contractor", "contractors", "homeowner", "homeowners", "designer", "designers"],
    ["rough", "ballpark", "estimate", "quote", "pricing", "price", "cost", "budget"],
    ["deposit", "approved", "approval", "fabrication"],
    ["measure", "measurement", "measuring", "template", "templating"],
    ["remove", "removal", "demo", "tearout", "existing"],
    ["slab", "slabs", "showroom", "selection", "material"],
    ["start", "started", "next", "forward", "availability", "schedule"]
  ];

  const INTENT_BOOSTS = [
    { query: INTENT_PATTERNS.hours, entry: /\b(monday|tuesday|wednesday|thursday|friday|am|pm)\b/i, boost: 18 },
    { query: INTENT_PATTERNS.location, entry: /\b(714|robeson|street|showroom|28305)\b/i, boost: 18 },
    { query: INTENT_PATTERNS.phone, entry: /\b(910|phone)\b/i, boost: 18 },
    { query: INTENT_PATTERNS.email, entry: /\b(info@|email)\b/i, boost: 18 },
    { query: INTENT_PATTERNS.estimate, entry: /\b(estimate|quote|consultation|pricing|free)\b/i, boost: 16 },
    { query: INTENT_PATTERNS.service, entry: /\b(service|coverage|north carolina|south carolina|tennessee|fayetteville|pinehurst)\b/i, boost: 14 },
    { query: /\b(granite|quartz|marble|quartzite|sintered|cultured|materials?|stone)\b/i, entry: /\b(granite|quartz|marble|quartzite|sintered|cultured|stone)\b/i, boost: 12 },
    { query: /\b(glass|mirror|mirrors|shower|door|doors|enclosure|enclosures)\b/i, entry: /\b(glass|mirror|mirrors|shower|door|doors|enclosure|enclosures)\b/i, boost: 12 },
    { query: INTENT_PATTERNS.process, entry: /\b(process|template|templating|fabrication|installation|install|digital|cnc)\b/i, boost: 12 },
    { query: INTENT_PATTERNS.care, entry: /\b(care|cleaning|clean|maintenance|warranty)\b/i, boost: 12 },
    { query: INTENT_PATTERNS.compare, entry: /\b(durability|heat resistance|stain resistance|scratch resistance|maintenance|price|pricing)\b/i, boost: 12 }
  ];

  const conversationState = {
    lastQuestion: "",
    lastIntent: "",
    lastContextTokens: [],
    lastSourcePages: [],
    lastFaqId: null,
    lastMaterialKeys: [],
    serviceKey: "",
    customerType: "",
    spaceType: "",
    materialPriority: "",
    buyingStage: "",
    lastConfidence: ""
  };

  function resetConversationState() {
    Object.assign(conversationState, {
      lastQuestion: "",
      lastIntent: "",
      lastContextTokens: [],
      lastSourcePages: [],
      lastFaqId: null,
      lastMaterialKeys: [],
      serviceKey: "",
      customerType: "",
      spaceType: "",
      materialPriority: "",
      buyingStage: "",
      lastConfidence: ""
    });
  }

  const compareState = {
    active: false,
    selectedKeys: []
  };

  const leadCaptureState = {
    active: false,
    sourceQuestion: "",
    currentStepKey: "",
    answers: {}
  };

  function serializeChatSources(bubble) {
    return Array.from(bubble.querySelectorAll(".chat-source-link")).map((link) => ({
      label: link.textContent.trim(),
      url: link.getAttribute("href") || link.href
    }));
  }

  function serializeChatSuggestions(bubble) {
    return Array.from(bubble.querySelectorAll(".chat-suggestion"))
      .map((button) => button.dataset.question || button.textContent.trim())
      .filter(Boolean);
  }

  function serializeChatActions(bubble) {
    return Array.from(bubble.querySelectorAll(".chat-action")).map((button) => ({
      label: button.textContent.trim(),
      action: button.dataset.action || "",
      materialKey: button.dataset.materialKey || "",
      prefillMessage: button.dataset.prefillMessage || "",
      chatSummary: button.dataset.chatSummary || "",
      projectType: button.dataset.projectType || "",
      question: button.dataset.question || "",
      phone: button.dataset.phone || "",
      email: button.dataset.email || ""
    }));
  }

  function serializeLeadFormBubble(bubble) {
    const leadForm = bubble.querySelector(".chat-lead-form");
    if (!leadForm || leadForm.dataset.completed === "true") {
      return null;
    }

    const readField = (name) => {
      const field = leadForm.querySelector(`[name="${name}"]`);
      return field ? field.value.trim() : "";
    };
    const status = leadForm.querySelector(".chat-form-status");
    const intro = bubble.querySelector(".chat-message-body");

    return {
      type: "lead_form",
      introText: intro ? intro.textContent.trim() : "",
      statusText: status ? status.textContent.trim() : "",
      statusError: Boolean(status && status.classList.contains("is-error")),
      prefill: {
        projectType: readField("project-type"),
        city: readField("city"),
        spaceType: readField("space-type"),
        materialInterest: readField("material-interest"),
        buildType: readField("build-type"),
        timeline: readField("timeline"),
        name: readField("name"),
        phone: readField("phone"),
        email: readField("email"),
        prefillMessage: readField("message"),
        chatSummary: readField("chat-summary"),
        customerType: readField("customer-type"),
        measurements: readField("measurements"),
        tileComplete: readField("tile-complete"),
        projectScope: readField("project-scope"),
        plansReady: readField("plans-ready"),
        pricingOrScheduling: readField("pricing-or-scheduling"),
        homeOrCommercial: readField("home-or-commercial")
      }
    };
  }

  function serializeChatBubble(bubble) {
    if (!bubble || bubble.classList.contains("chat-status")) {
      return null;
    }

    const formBubble = serializeLeadFormBubble(bubble);
    if (formBubble) {
      return formBubble;
    }

    const body = bubble.querySelector(".chat-message-body");
    if (!body) {
      return null;
    }

    return {
      type: "message",
      sender: bubble.classList.contains("user") ? "user" : "bot",
      text: body.textContent.trim(),
      sources: serializeChatSources(bubble),
      suggestions: serializeChatSuggestions(bubble),
      actions: serializeChatActions(bubble)
    };
  }

  function serializeChatTranscript(maxItems) {
    const transcript = Array.from(messages.children)
      .map((bubble) => serializeChatBubble(bubble))
      .filter(Boolean);

    if (typeof maxItems === "number" && maxItems >= 0) {
      return transcript.slice(-maxItems);
    }

    return transcript;
  }

  function normalizeTranscriptLine(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function buildChatTranscriptText(options) {
    const transcript = serializeChatTranscript(
      options && typeof options.maxItems === "number" ? options.maxItems : null
    );

    const lines = [];

    transcript.forEach((item) => {
      if (!item) {
        return;
      }

      if (item.type === "message") {
        const text = normalizeTranscriptLine(item.text);
        if (!text) {
          return;
        }

        lines.push(`${item.sender === "user" ? "Visitor" : "Bot"}: ${text}`);
        return;
      }

      if (item.type === "lead_form") {
        const introText = normalizeTranscriptLine(item.introText);
        if (introText) {
          lines.push(`Bot: ${introText}`);
        }
      }
    });

    return lines.join("\n");
  }

  function buildChatSessionSnapshot() {
    return {
      sessionId: chatSessionId,
      pagePath: window.location.pathname,
      panelOpen: panel.classList.contains("is-open"),
      hasGreeted,
      transcript: serializeChatTranscript(40),
      conversationState: {
        ...conversationState
      },
      compareState: {
        active: compareState.active,
        selectedKeys: compareState.selectedKeys.slice()
      },
      leadCaptureState: {
        active: leadCaptureState.active,
        sourceQuestion: leadCaptureState.sourceQuestion,
        currentStepKey: leadCaptureState.currentStepKey,
        answers: {
          ...leadCaptureState.answers
        }
      }
    };
  }

  function persistChatSessionState() {
    if (isRestoringChatSession) {
      return;
    }

    writeChatSessionState(buildChatSessionSnapshot());
  }

  function normalizeText(value) {
    return (value || "")
      .toLowerCase()
      .replace(/&/g, " and ")
      .replace(/[^a-z0-9@.\s-]/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function singularize(token) {
    if (token.length > 4 && token.endsWith("ies")) return token.slice(0, -3) + "y";
    if (token.length > 4 && token.endsWith("es")) return token.slice(0, -2);
    if (token.length > 3 && token.endsWith("s")) return token.slice(0, -1);
    return token;
  }

  function tokenize(value) {
    return normalizeText(value)
      .split(" ")
      .map((token) => singularize(token.trim()))
      .filter((token) => token && token.length > 1 && !STOP_WORDS.has(token));
  }

  function expandTokens(tokens) {
    const expanded = new Set(tokens);

    tokens.forEach((token) => {
      expanded.add(singularize(token));
      SYNONYM_GROUPS.forEach((group) => {
        if (group.includes(token)) {
          group.forEach((related) => expanded.add(singularize(related)));
        }
      });
    });

    return Array.from(expanded);
  }

  function pageLabel(entry) {
    return entry.pageTitle
      .replace(/\s*\|\s*Olive Glass & Marble.*$/i, "")
      .replace(/^Olive Glass & Marble\s*\|\s*/i, "")
      .trim() || entry.pageUrl;
  }

  function cleanSnippet(value) {
    return (value || "")
      .replace(/^\|\s*/, "")
      .replace(/\s+\|\s+/g, " | ")
      .replace(/\s+:\s+/g, ": ")
      .replace(/\s{2,}/g, " ")
      .trim();
  }

  function trimSnippetForReply(value) {
    let text = cleanSnippet(value);
    const separatorIndex = text.indexOf(":");
    if (separatorIndex !== -1) {
      const prefix = text.slice(0, separatorIndex).trim();
      const suffix = text.slice(separatorIndex + 1).trim();
      if (suffix && (prefix.includes("|") || prefix.length <= 72)) {
        text = suffix;
      }
    }

    return text
      .replace(/^Yes\.\s*/i, "Yes, ")
      .replace(/^No\.\s*/i, "No, ")
      .trim();
  }

  function trimSentenceEnding(value) {
    return trimSnippetForReply(value).replace(/[.!?]+$/g, "").trim();
  }

  function ensureSentence(text) {
    const cleaned = trimSnippetForReply(text);
    if (!cleaned) return "";
    return /[.!?]$/.test(cleaned) ? cleaned : `${cleaned}.`;
  }

  function finalizeSentence(text) {
    const cleaned = (text || "").replace(/\s+/g, " ").trim();
    if (!cleaned) return "";
    return /[.!?]$/.test(cleaned) ? cleaned : `${cleaned}.`;
  }

  function dedupeBy(items, getKey) {
    const seen = new Set();
    return items.filter((item) => {
      const key = getKey(item);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function titleCase(value) {
    return (value || "")
      .replace(/\s+/g, " ")
      .trim()
      .split(" ")
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(" ");
  }

  function getFileExtension(fileName) {
    const parts = String(fileName || "").toLowerCase().split(".");
    return parts.length > 1 ? parts.pop() : "";
  }

  function formatUploadSize(bytes) {
    return `${Math.round((bytes / (1024 * 1024)) * 10) / 10}MB`;
  }

  function joinParts(parts) {
    return parts.filter(Boolean).join(" ").replace(/\s+/g, " ").trim();
  }

  function normalizePhrase(value) {
    return normalizeText(value).replace(/\s+/g, " ").trim();
  }

  function resolveQuickReplyPrompt(value) {
    const normalized = normalizePhrase(value);
    if (QUICK_REPLY_PROMPTS[value]) return QUICK_REPLY_PROMPTS[value];
    if (/^i want (?:a )?shower$/.test(normalized)) return "I want a shower enclosure";
    if (/request a quote|get a quote|quote now|pricing|pricing help|rough quote|starting quote/.test(normalized)) return "I want a quote";
    if (/talk to someone|have someone contact me|leave your name and phone number|contact me/.test(normalized)) return "Can someone contact me?";
    if (/quote for shower glass|quote for a shower project|shower glass options or a quote|quote for (?:a )?shower enclosure|quote for (?:a )?bath enclosure|quote for (?:a )?tub enclosure/.test(normalized)) {
      return "I want a shower enclosure";
    }
    if (/i need (?:a )?shower enclosure|i need (?:a )?bath enclosure|i need (?:a )?tub enclosure/.test(normalized)) {
      return "I want a shower enclosure";
    }
    if (/help with countertops or shower glass/.test(normalized)) return "What services do you offer?";
    if (/help getting started/.test(normalized)) return "How do I get started?";
    if (/countertop option/.test(normalized)) return "What countertop options do you offer?";
    if (/compare quartz and granite/.test(normalized)) return "What's the difference between quartz and granite?";
    if (/compare materials|help comparing|comparing materials/.test(normalized)) return "Compare Materials";
    if (/help choosing a material|help choosing the right surface|help narrowing it down|help narrow(?:ing)? it down|help me choose/.test(normalized)) return "Help me decide";
    if (/project process|how does the process work/.test(normalized)) return "What is the process from start to finish?";
    if (/shower glass/.test(normalized)) return "I need a shower door";
    if (/low maintenance is my priority/.test(normalized)) return "What countertop material is best if I want low maintenance?";
    if (/i want natural stone/.test(normalized)) return "What's the difference between quartz and granite?";
    if (/i want a high-end look/.test(normalized)) return "Is marble a good choice?";
    return value;
  }

  function lowercaseFirst(text) {
    if (!text) return "";
    return text.charAt(0).toLowerCase() + text.slice(1);
  }

  function capitalizeFirst(text) {
    if (!text) return "";
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function hashString(value) {
    return Array.from(String(value || "")).reduce((total, character) => (
      ((total << 5) - total) + character.charCodeAt(0)
    ), 0);
  }

  function pickReplyVariant(seed, options) {
    const variants = (options || []).filter(Boolean);
    if (!variants.length) return "";
    const index = Math.abs(hashString(seed)) % variants.length;
    return variants[index];
  }

  function joinWithAnd(items) {
    const values = items.filter(Boolean);
    if (!values.length) return "";
    if (values.length === 1) return values[0];
    if (values.length === 2) return `${values[0]} and ${values[1]}`;
    return `${values.slice(0, -1).join(", ")}, and ${values[values.length - 1]}`;
  }

  function isCountertopMaterialKey(materialKey) {
    return ["quartz", "granite", "marble", "quartzite"].includes(normalizeText(materialKey));
  }

  function getMaterialTermByKey(materialKey) {
    const normalizedKey = normalizeText(materialKey);
    return MATERIAL_TERMS.find((term) => normalizeText(term.key) === normalizedKey) || null;
  }

  function getCountertopMaterialTerms(question) {
    return extractMaterialTerms(question).filter((term) => isCountertopMaterialKey(term.key));
  }

  function detectSpaceType(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";

    const match = SPACE_TYPE_RULES.find((rule) => rule.regex.test(normalized));
    return match ? match.label : "";
  }

  function detectMaterialPriority(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";

    const match = MATERIAL_PRIORITY_RULES.find((rule) => rule.regex.test(normalized));
    return match ? match.label : "";
  }

  function detectResearchStage(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";
    if (isDirectPurchaseLeadIntent(normalized)) return "quote_ready";

    const match = RESEARCH_STAGE_RULES.find((rule) => rule.regex.test(normalized));
    return match ? match.key : "";
  }

  function getMaterialProfile(materialKey) {
    return MATERIAL_PROFILES[normalizeText(materialKey)] || null;
  }

  function buildSingleMaterialProfileAnswer(term) {
    const profile = getMaterialProfile(term.key);
    if (!profile) return "";

    return `${profile.name} is a ${lowercaseFirst(profile.origin)} with ${profile.durability.toLowerCase()} durability and ${profile.maintenance.toLowerCase()} maintenance. It is a strong fit for ${lowercaseFirst(profile.bestFor)}. ${profile.notes}`;
  }

  function buildMaterialComparisonAnswer(question, materialTerms) {
    const profiles = dedupeBy(materialTerms, (term) => term.key)
      .map((term) => ({ term, profile: getMaterialProfile(term.key) }))
      .filter((item) => item.profile);

    if (profiles.length < 2) {
      return "";
    }

    const normalizedQuestion = normalizeText(question);

    if (profiles.length === 2) {
      const first = profiles[0].profile;
      const second = profiles[1].profile;

      if (/\b(maint|maintenance|clean|cleaning|seal|sealing)\b/.test(normalizedQuestion)) {
        return `${first.name} is ${first.maintenance.toLowerCase()} maintenance, while ${second.name} is ${second.maintenance.toLowerCase()} maintenance. ${first.notes} ${second.notes}`;
      }

      if (/\b(heat|hot|trivet|pan|cooktop)\b/.test(normalizedQuestion)) {
        return `${first.name} has ${first.heatResistance.toLowerCase()} heat resistance, while ${second.name} has ${second.heatResistance.toLowerCase()} heat resistance. ${first.notes}`;
      }

      if (/\b(kitchen|bathroom|bath|best for|good for|space)\b/.test(normalizedQuestion)) {
        return `${first.name} is a good fit for ${lowercaseFirst(first.bestFor)}, while ${second.name} is a strong fit for ${lowercaseFirst(second.bestFor)}.`;
      }

      if (/\b(look|appearance|style|pattern|veining|natural)\b/.test(normalizedQuestion)) {
        return `${first.name} offers ${lowercaseFirst(first.appearance)}, while ${second.name} gives you ${lowercaseFirst(second.appearance)}. ${first.name} is a ${lowercaseFirst(first.origin)}, and ${second.name} is a ${lowercaseFirst(second.origin)}.`;
      }

      return `${first.name} is ${first.maintenance.toLowerCase()} maintenance with ${lowercaseFirst(first.appearance)}. ${second.name} is ${second.maintenance.toLowerCase()} maintenance with ${lowercaseFirst(second.appearance)}. ${first.name} is best for ${lowercaseFirst(first.bestFor)}, while ${second.name} is best for ${lowercaseFirst(second.bestFor)}.`;
    }

    return `${joinWithAnd(profiles.map((item) => item.profile.name))} all have different strengths. ${profiles.map((item) => `${item.profile.name} offers ${item.profile.durability.toLowerCase()} durability, ${item.profile.maintenance.toLowerCase()} maintenance, and is best for ${lowercaseFirst(item.profile.bestFor)}`).join(". ")}.`;
  }

  function resetMaterialCompareState(options) {
    compareState.active = false;
    compareState.selectedKeys = [];

    if (!options || options.persist !== false) {
      persistChatSessionState();
    }
  }

  function getMaterialQuickOptions(includeCompare) {
    return WELCOME_QUICK_REPLIES.filter((label) => includeCompare || label !== "Compare Materials");
  }

  function buildMaterialCompareActions(excludedKeys) {
    const blocked = new Set((excludedKeys || []).map((key) => normalizeText(key)));
    return MATERIAL_TERMS
      .filter((term) => isCountertopMaterialKey(term.key) && !blocked.has(normalizeText(term.key)))
      .map((term) => ({
        label: titleCase(term.label),
        action: "select-material-compare",
        materialKey: term.key
      }));
  }

  function shouldStartMaterialCompareFlow(question) {
    const normalized = normalizePhrase(question);
    if (normalized === "compare materials") {
      return true;
    }

    if (!INTENT_PATTERNS.compare.test(question)) {
      return false;
    }

    return getCountertopMaterialTerms(question).length < 2;
  }

  function buildMaterialComparePrompt(selectedKeys) {
    const selectedProfiles = (selectedKeys || [])
      .map((key) => getMaterialProfile(key))
      .filter(Boolean);

    if (!selectedProfiles.length) {
      return "Choose any two countertop materials to compare.";
    }

    if (selectedProfiles.length === 1) {
      return `${selectedProfiles[0].name} is selected. Choose one more material to compare.`;
    }

    return "Choose the materials you want to compare.";
  }

  function startMaterialCompareFlow(prefilledKeys) {
    compareState.active = true;
    compareState.selectedKeys = dedupeBy(
      (prefilledKeys || []).filter((key) => isCountertopMaterialKey(key)),
      (key) => normalizeText(key)
    ).slice(0, 1);

    if (compareState.selectedKeys.length >= 2) {
      compareState.selectedKeys = compareState.selectedKeys.slice(0, 2);
    }

    addMessage(buildMaterialComparePrompt(compareState.selectedKeys), "bot", {
      actions: buildMaterialCompareActions(compareState.selectedKeys),
      suggestions: getMaterialQuickOptions(false)
    });
  }

  function handleMaterialCompareSelection(materialKey) {
    const normalizedKey = normalizeText(materialKey);
    const profile = getMaterialProfile(normalizedKey);
    if (!profile || !isCountertopMaterialKey(normalizedKey)) {
      return;
    }

    if (!compareState.active) {
      compareState.active = true;
      compareState.selectedKeys = [];
    }

    addMessage(profile.name, "user");

    if (!compareState.selectedKeys.includes(normalizedKey)) {
      compareState.selectedKeys.push(normalizedKey);
    }

    if (compareState.selectedKeys.length < 2) {
      addMessage(buildMaterialComparePrompt(compareState.selectedKeys), "bot", {
        actions: buildMaterialCompareActions(compareState.selectedKeys),
        suggestions: getMaterialQuickOptions(false)
      });
      return;
    }

    const selectedTerms = compareState.selectedKeys
      .map((key) => MATERIAL_TERMS.find((term) => normalizeText(term.key) === key))
      .filter(Boolean);

    const comparisonText = buildMaterialComparisonAnswer(
      compareState.selectedKeys.join(" vs "),
      selectedTerms
    );
    const comparisonReply = comparisonText
      ? joinParts([finalizeSentence(comparisonText), "Would you like to compare more types?"])
      : "I can help compare those materials based on maintenance, durability, heat resistance, and best-fit use. Would you like to compare more types?";

    addMessage(comparisonReply, "bot", {
      actions: [
        { label: "Compare more types", action: "start-material-compare" }
      ],
      suggestions: getMaterialQuickOptions(false)
    });

    resetMaterialCompareState();
  }

  function extractMaterialTerms(question) {
    return dedupeBy(
      MATERIAL_TERMS.filter((item) => item.regex.test(question)),
      (item) => item.key
    );
  }

  function extractServicePlace(question) {
    const directMatch = question.match(/\b(?:serve|serving|service(?: area)?(?: in)?|cover)\s+([a-z][a-z\s.'-]+)\??$/i);
    if (directMatch) return directMatch[1].trim();

    const inMatch = question.match(/\b(?:in|around|near)\s+([a-z][a-z\s.'-]+)\??$/i);
    if (inMatch && INTENT_PATTERNS.service.test(question)) return inMatch[1].trim();

    return "";
  }

  function looksLikeLeadInfo(text) {
    return EMAIL_PATTERN.test(text) || PHONE_PATTERN.test(text);
  }

  function detectCustomerType(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";

    if (CHAT_PLAYBOOK.customerTypes.builder_or_contractor.triggers.some((trigger) => normalized.includes(normalizePhrase(trigger)))) {
      return "builder_or_contractor";
    }

    if (CHAT_PLAYBOOK.customerTypes.homeowner.triggers.some((trigger) => normalized.includes(normalizePhrase(trigger)))) {
      return "homeowner";
    }

    return "";
  }

  function inferServiceKey(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";
    const directPurchaseService = detectDirectPurchaseService(normalized);
    if (directPurchaseService) return directPurchaseService;
    if (/\b(shower|enclosure|door)\b/.test(normalized)) return "shower_glass";
    if (/\b(mirror|mirrors|custom glass|glass project|glass partition)\b/.test(normalized)) return "custom_glass";
    if (/\b(counter\s?tops?|granite|quartz|marble|quartzite|kitchen|bathroom|vanity|outdoor kitchen)\b/.test(normalized)) return "countertops";
    return "";
  }

  function detectDirectPurchaseService(text) {
    const normalized = normalizeText(text);
    if (!normalized) return "";

    const matchedRule = DIRECT_PURCHASE_SERVICE_RULES.find((rule) => rule.regex.test(normalized));
    return matchedRule ? matchedRule.serviceKey : "";
  }

  function isDirectPurchaseLeadIntent(text) {
    const normalized = normalizeText(text);
    if (!normalized) return false;

    return Boolean(detectDirectPurchaseService(normalized))
      || /\b(?:i|we)\s+(?:want|need|am looking for|are looking for)\s+(?:a\s+)?(?:quote|estimate|pricing)\b/i.test(normalized);
  }

  function projectTypeFromServiceKey(serviceKey) {
    switch (serviceKey) {
      case "countertops":
        return "Countertops";
      case "shower_glass":
        return "Shower Glass";
      case "custom_glass":
        return "Custom Glass / Mirrors";
      default:
        return "";
    }
  }

  function formProjectTypeValue(serviceKey) {
    switch (serviceKey) {
      case "countertops":
        return "countertops";
      case "shower_glass":
        return "shower-doors";
      case "custom_glass":
        return "mirrors";
      default:
        return "";
    }
  }

  function isBusinessHours() {
    const now = new Date();
    const day = now.getDay();
    const minutes = now.getHours() * 60 + now.getMinutes();

    if (day >= 1 && day <= 4) {
      return minutes >= 8 * 60 && minutes < 17 * 60;
    }

    if (day === 5) {
      return minutes >= 8 * 60 && minutes < 16 * 60;
    }

    return false;
  }

  function buildTalkToSomeoneText() {
    if (isBusinessHours()) {
      return `Absolutely. You can call Olive Glass & Marble at ${BUSINESS_PROFILE.phone}, or leave your contact information here and someone can follow up.`;
    }

    return "Thanks for reaching out to Olive Glass & Marble. If we missed you, leave your name, phone number, and a few details about your project, and someone will follow up.";
  }

  function hasLeadProjectContext(answers) {
    return Boolean(
      answers.projectType ||
      answers.city ||
      answers.spaceType ||
      answers.glassProjectType ||
      answers.materialInterest ||
      answers.buildType ||
      answers.measurements ||
      answers.tileComplete ||
      answers.projectScope ||
      answers.plansReady ||
      answers.pricingOrScheduling ||
      answers.homeOrCommercial ||
      answers.replacingExisting ||
      answers.timeline
    );
  }

  function addLeadIdentitySteps(steps, answers) {
    const hasProjectContext = hasLeadProjectContext(answers);

    if (!answers.name) {
      steps.push(buildLeadStep(
        "name",
        hasProjectContext
          ? "Thanks, that gives me a good starting point. What's your name?"
          : "What's your name?"
      ));
    }

    if (!answers.contact && !answers.phone && !answers.email) {
      steps.push(buildLeadStep(
        "contact",
        hasProjectContext
          ? "Great. What's the best phone number or email for follow-up?"
          : "What's the best phone number or email for follow-up?"
      ));
    }
  }

  function addCountertopLeadSteps(steps, answers, customerType) {
    if (!answers.spaceType) {
      steps.push(buildLeadStep("spaceType", "What space is this for?", [
        "Kitchen",
        "Bathroom",
        "Outdoor kitchen",
        "Another space"
      ]));
    }

    if (!answers.materialInterest) {
      steps.push(buildLeadStep("materialInterest", "Do you already know what material you're leaning toward, or would you like help narrowing it down?", [
        "I know the material",
        "I need help deciding"
      ]));
    }

    if (!answers.buildType) {
      steps.push(buildLeadStep("buildType", "Is this a remodel or new construction?", [
        "New construction",
        "Remodel"
      ]));
    }

    if (customerType === "builder_or_contractor") {
      if (!answers.projectScope) {
        steps.push(buildLeadStep("projectScope", "Is this for one project or multiple?", [
          "One project",
          "Multiple projects"
        ]));
      }
      if (!answers.plansReady) {
        steps.push(buildLeadStep("plansReady", "Do you already have plans or measurements ready?", [
          "Yes, I have plans",
          "Not yet"
        ]));
      }
      if (!answers.pricingOrScheduling) {
        steps.push(buildLeadStep("pricingOrScheduling", "Are you mainly looking for pricing, scheduling, or both?", [
          "Pricing",
          "Scheduling",
          "Both"
        ]));
      }
      return;
    }

    if (answers.buildType && /remodel/i.test(answers.buildType) && !answers.replacingExisting) {
      steps.push(buildLeadStep("replacingExisting", "Are you replacing existing countertops?", [
        "Yes, replacing existing countertops",
        "No, not replacing existing countertops"
      ]));
    }

    if (!answers.measurements) {
      steps.push(buildLeadStep("measurements", "Do you have measurements or plans we can work from yet?", [
        "Yes, I do",
        "Not yet"
      ]));
    }
  }

  function addShowerGlassLeadSteps(steps, answers) {
    if (!answers.buildType) {
      steps.push(buildLeadStep("buildType", "Is this for a new build or a remodel?", [
        "New build",
        "Remodel"
      ]));
    }

    if (!answers.measurements) {
      steps.push(buildLeadStep("measurements", "Do you already have the shower dimensions?", [
        "Yes, I have dimensions",
        "No, I need a measure"
      ]));
    }

    if (!answers.tileComplete) {
      steps.push(buildLeadStep("tileComplete", "Is the tile complete yet?", [
        "Yes, tile is complete",
        "Not yet"
      ]));
    }
  }

  function addCustomGlassLeadSteps(steps, answers) {
    if (!answers.glassProjectType) {
      steps.push(buildLeadStep("glassProjectType", "What type of glass project are you working on?"));
    }

    if (!answers.measurements) {
      steps.push(buildLeadStep("measurements", "Do you have measurements already?", [
        "Yes, I do",
        "Not yet"
      ]));
    }

    if (!answers.homeOrCommercial) {
      steps.push(buildLeadStep("homeOrCommercial", "Is this for a home or commercial space?", [
        "Home",
        "Commercial"
      ]));
    }
  }

  function buildLeadWarmIntro(context, openingAnswers) {
    switch (openingAnswers.serviceKey || context.serviceKey) {
      case "countertops":
        return "Absolutely. We'd love to help with your countertops project. I can get a few details here so the team has the right starting point.";
      case "shower_glass":
        return "Absolutely. We'd love to help with your shower enclosure project. I can get a few details here so we understand the layout and next step.";
      case "custom_glass":
        return "Absolutely. We'd love to help with your glass project. I can get a few details here so the team has the right starting point.";
      default:
        return "Absolutely. I can help get that started and gather the right details for the team.";
    }
  }

  function buildLeadFlowIntroText(context) {
    const openingAnswers = {
      serviceKey: context.serviceKey || inferServiceKey(context.question),
      projectType: projectTypeFromServiceKey(context.serviceKey || inferServiceKey(context.question)),
      customerType: context.customerType || detectCustomerType(context.question),
      name: "",
      phone: "",
      email: "",
      contact: ""
    };
    const firstStep = getLeadFlowSteps(openingAnswers, context.question)[0];
    const intro = buildLeadWarmIntro(context, openingAnswers);

    if (!firstStep) {
      return intro;
    }

    return joinParts([intro, firstStep.prompt]);
  }

  function detectProjectType(text) {
    const normalized = normalizeText(text);
    if (/\b(shower|enclosure|door)\b/.test(normalized)) return "shower-doors";
    if (/\b(mirror|glass)\b/.test(normalized)) return "mirrors";
    if (/\b(commercial|builder|designer|office|business|contractor)\b/.test(normalized)) return "commercial";
    if (/\b(counter\s?tops?|granite|quartz|marble|quartzite|stone|backsplash|sink)\b/.test(normalized)) return "countertops";
    return "";
  }

  function shouldOfferLeadCapture(question) {
    return LEAD_INTENT_PATTERN.test(question) || looksLikeLeadInfo(question) || isDirectPurchaseLeadIntent(question);
  }

  function buildLeadActions(question) {
    const normalized = normalizeText(question);
    const contactDetails = parseContactDetails(question);
    let label = "Start project questions";
    if (/\b(quote|estimate|pricing|price|cost)\b/.test(normalized)) {
      label = "Get a Quote";
    } else if (/\b(contact|call me|reach out|follow up|ready|move forward)\b/.test(normalized)) {
      label = "Talk to Someone";
    }

    return [
      {
        label,
        action: "start-lead-flow",
        chatSummary: question ? `Chat inquiry: ${question}` : "",
        projectType: detectProjectType(question),
        question: question || "I want a quote",
        phone: contactDetails.phone,
        email: contactDetails.email
      },
      {
        label: "Use Form Instead",
        action: "open-lead-form",
        chatSummary: question ? `Chat inquiry: ${question}` : "",
        projectType: detectProjectType(question),
        question: question || "I want a quote",
        phone: contactDetails.phone,
        email: contactDetails.email
      }
    ];
  }

  function createElement(tagName, className, text) {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (typeof text === "string") element.textContent = text;
    return element;
  }

  function detectIntent(question, materialTerms) {
    if (INTENT_PATTERNS.hours.test(question)) return "hours";
    if (INTENT_PATTERNS.location.test(question)) return "location";
    if (INTENT_PATTERNS.phone.test(question) && !INTENT_PATTERNS.email.test(question)) return "phone";
    if (INTENT_PATTERNS.email.test(question) && !INTENT_PATTERNS.phone.test(question)) return "email";
    if (INTENT_PATTERNS.estimate.test(question)) return "estimate";
    if (INTENT_PATTERNS.service.test(question)) return "service";
    if (INTENT_PATTERNS.process.test(question)) return "process";
    if (INTENT_PATTERNS.care.test(question)) return "care";
    if (INTENT_PATTERNS.glass.test(question) && !materialTerms.some((term) => /granite|quartz|marble|quartzite|sintered|cultured/i.test(term.key))) return "glass";
    if (INTENT_PATTERNS.compare.test(question) || materialTerms.length >= 2) return "compare";
    if (materialTerms.length) return "materials";
    if (INTENT_PATTERNS.contact.test(question)) return "contact";
    return "generic";
  }

  function detectGuidedFlow(question, intent) {
    const normalizedQuestion = normalizePhrase(question);
    const directPurchaseService = detectDirectPurchaseService(normalizedQuestion);

    if (directPurchaseService) {
      return CHAT_PLAYBOOK.flows.find((flow) => flow.key === "purchase") || null;
    }

    return CHAT_PLAYBOOK.flows.find((flow) => {
      if (!flow.triggers.some((trigger) => normalizedQuestion.includes(normalizePhrase(trigger)))) {
        return false;
      }

      if (flow.key === "material-help" && (
        intent === "compare" ||
        /\b(easier to maintain|low maintenance|heat resistant|better for|which is better|difference)\b/i.test(question)
      )) {
        return false;
      }

      if (flow.key === "ready" && (intent === "process" || /\b(template|templating|measure|installation|process)\b/i.test(question))) {
        return false;
      }

      return true;
    }) || null;
  }

  function isFollowUpQuestion(question) {
    const tokens = tokenize(question);
    return FOLLOW_UP_PATTERNS.some((pattern) => pattern.test(question)) || tokens.length <= 3;
  }

  function isIncompletePrompt(question) {
    const normalized = normalizePhrase(question);
    if (!normalized) return false;

    return /^(i want|i need|i want to know|i'm looking|i am looking|looking for|help me|can you help|tell me|what about|how about)$/i.test(normalized);
  }

  function buildSearchContext(question) {
    const directMaterialTerms = extractMaterialTerms(question);
    const intent = detectIntent(question, directMaterialTerms);
    const flow = detectGuidedFlow(question, intent);
    const incompletePrompt = isIncompletePrompt(question);
    const servicePlace = extractServicePlace(question);
    const directSpaceType = detectSpaceType(question);
    const directMaterialPriority = detectMaterialPriority(question);
    const directResearchStage = detectResearchStage(question);
    const explicitTopic = Boolean(
      directMaterialTerms.length ||
      servicePlace ||
      flow ||
      directSpaceType ||
      directMaterialPriority ||
      INTENT_PATTERNS.hours.test(question) ||
      INTENT_PATTERNS.location.test(question) ||
      INTENT_PATTERNS.phone.test(question) ||
      INTENT_PATTERNS.email.test(question) ||
      INTENT_PATTERNS.service.test(question) ||
      INTENT_PATTERNS.process.test(question) ||
      INTENT_PATTERNS.care.test(question) ||
      INTENT_PATTERNS.glass.test(question)
    );
    const followUp = !incompletePrompt && isFollowUpQuestion(question) && Boolean(conversationState.lastQuestion) && !explicitTopic;
    const carriedTokens = followUp ? conversationState.lastContextTokens.slice(0, 8) : [];
    const rememberedMaterialTerms = (!incompletePrompt && !directMaterialTerms.length && (
      followUp ||
      intent === "materials" ||
      intent === "compare" ||
      intent === "care"
    ))
      ? conversationState.lastMaterialKeys
        .map((key) => getMaterialTermByKey(key))
        .filter(Boolean)
      : [];
    const materialTerms = directMaterialTerms.length ? directMaterialTerms : rememberedMaterialTerms;
    const serviceKey = inferServiceKey(question)
      || (!incompletePrompt && materialTerms.length ? "countertops" : "")
      || (!incompletePrompt ? conversationState.serviceKey : "")
      || (!incompletePrompt ? inferServiceKey(conversationState.lastQuestion) : "");
    const customerType = detectCustomerType(question)
      || (!incompletePrompt ? conversationState.customerType : "")
      || (!incompletePrompt ? detectCustomerType(conversationState.lastQuestion) : "");
    const spaceType = directSpaceType
      || ((!incompletePrompt && (followUp || intent === "materials" || intent === "compare" || intent === "estimate"))
        ? conversationState.spaceType
        : "");
    const materialPriority = directMaterialPriority
      || ((!incompletePrompt && (followUp || intent === "materials" || intent === "compare"))
        ? conversationState.materialPriority
        : "");
    const buyingStage = directResearchStage
      || ((!incompletePrompt && (followUp || shouldOfferLeadCapture(question)))
        ? conversationState.buyingStage
        : "");
    const rememberedContextParts = [];

    materialTerms.forEach((term) => {
      if (!directMaterialTerms.length) {
        rememberedContextParts.push(term.label);
      }
    });

    if (!directSpaceType && spaceType) {
      rememberedContextParts.push(spaceType);
    }

    if (!directMaterialPriority && materialPriority) {
      rememberedContextParts.push(materialPriority);
    }

    if (!inferServiceKey(question) && serviceKey) {
      rememberedContextParts.push(projectTypeFromServiceKey(serviceKey));
    }

    const expandedQuestion = dedupeBy(
      [question, ...carriedTokens, ...rememberedContextParts].filter(Boolean),
      (value) => value
    ).join(" ");
    const baseTokens = dedupeBy(tokenize(expandedQuestion), (token) => token);

    return {
      question,
      expandedQuestion,
      followUp,
      flow,
      materialTerms,
      servicePlace,
      serviceKey,
      customerType,
      spaceType,
      materialPriority,
      buyingStage,
      incompletePrompt,
      intent,
      baseTokens,
      expandedTokens: expandTokens(baseTokens)
    };
  }

  const searchIndex = knowledgeEntries.map((entry, index) => {
    const searchableText = [entry.pageTitle, entry.section, entry.text, entry.pageUrl].join(" ");
    const normalized = normalizeText(searchableText);
    return {
      id: index,
      pageTitle: entry.pageTitle,
      pageUrl: entry.pageUrl,
      pageName: pageLabel(entry),
      section: entry.section,
      text: cleanSnippet(entry.text),
      replyText: trimSnippetForReply(entry.text),
      normalized,
      tokenSet: new Set(tokenize(searchableText))
    };
  });

  const faqSourceEntries = dedupeBy(
    [
      ...BUSINESS_PROFILE.faqs.map((faq) => ({
        question: faq.question,
        answer: faq.answer,
        keywords: [],
        followUp: "",
        nextStepOptions: [],
        sourceType: "business-faq"
      })),
      ...CHAT_PLAYBOOK.keywordFaqs.map((faq) => ({
        question: faq.question,
        answer: faq.answer,
        keywords: faq.keywords || [],
        followUp: faq.followUp || "",
        nextStepOptions: faq.followUp ? [faq.followUp] : [],
        sourceType: "playbook"
      }))
    ],
    (entry) => normalizePhrase(entry.question)
  );

  const faqIndex = faqSourceEntries.map((faq, index) => {
    const searchableText = [
      faq.question,
      (faq.keywords || []).join(" "),
      faq.answer,
      BUSINESS_PROFILE.businessName,
      BUSINESS_PROFILE.location,
      BUSINESS_PROFILE.phone,
      BUSINESS_PROFILE.serviceArea.join(" ")
    ].join(" ");
    const materialTerms = extractMaterialTerms(searchableText);

    return {
      id: index,
      question: faq.question,
      answer: finalizeSentence(faq.answer),
      normalized: normalizeText(searchableText),
      tokenSet: new Set(tokenize(searchableText)),
      materialKeys: materialTerms.map((term) => normalizeText(term.key)),
      intent: detectIntent(searchableText, materialTerms),
      followUp: faq.followUp || "",
      nextStepOptions: faq.nextStepOptions || [],
      sourceType: faq.sourceType
    };
  });

  function scoreEntry(entry, context) {
    let score = 0;
    const normalizedQuestion = normalizeText(context.expandedQuestion);

    if (!context.expandedTokens.length) return 0;

    if (normalizedQuestion && entry.normalized.includes(normalizedQuestion)) {
      score += 30;
    }

    context.baseTokens.forEach((token) => {
      if (entry.tokenSet.has(token)) {
        score += token.length >= 7 ? 7 : 5;
        return;
      }

      if (entry.normalized.includes(token)) {
        score += token.length >= 7 ? 4 : 3;
      }
    });

    context.expandedTokens.forEach((token) => {
      if (context.baseTokens.includes(token)) return;
      if (entry.tokenSet.has(token)) {
        score += 2;
      }
    });

    if (context.baseTokens.length > 1) {
      const phrase = context.baseTokens.join(" ");
      if (entry.normalized.includes(phrase)) {
        score += 12;
      }
    }

    if (context.followUp && conversationState.lastSourcePages.includes(entry.pageUrl)) {
      score += 7;
    }

    if (context.followUp && conversationState.lastIntent === context.intent) {
      score += 4;
    }

    INTENT_BOOSTS.forEach((intentBoost) => {
      if (intentBoost.query.test(context.question) && intentBoost.entry.test(entry.text)) {
        score += intentBoost.boost;
      }
    });

    if (context.intent === "location") {
      if (/\b(714|robeson|street|28305)\b/i.test(entry.text)) score += 14;
      if (/contact\.html$/i.test(entry.pageUrl)) score += 8;
      if (!/\b(714|robeson|street|28305)\b/i.test(entry.text) && /\b(monday|thursday|friday|am|pm)\b/i.test(entry.text)) {
        score -= 12;
      }
    }

    if (context.intent === "phone" && PHONE_PATTERN.test(entry.text)) {
      score += 14;
    }

    if (context.intent === "email" && EMAIL_PATTERN.test(entry.text)) {
      score += 14;
    }

    if (context.intent === "hours" && /\b(monday|thursday|friday|am|pm)\b/i.test(entry.text)) {
      score += 10;
    }

    if (context.intent === "materials" || context.intent === "compare") {
      const materialCount = new Set(
        (entry.text.match(/\b(granite|quartz|marble|quartzite|sintered|cultured)\b/gi) || [])
          .map((match) => match.toLowerCase())
      ).size;

      if (materialCount >= 2) score += materialCount * 4;
      if (/(^|\/)(index|countertops|resources)\.html$/i.test(entry.pageUrl)) score += 6;
      context.materialTerms.forEach((term) => {
        if (new RegExp(`\\b${normalizeText(term.key).replace(/\s+/g, "\\s+")}\\b`, "i").test(normalizeText(entry.text))) {
          score += 8;
        }
        if (new RegExp(normalizeText(term.key).replace(/\s+/g, ".*"), "i").test(normalizeText(entry.pageUrl))) {
          score += 10;
        }
      });
    }

    if (context.intent === "service") {
      if (/contact\.html$|resources\.html$|gallery\.html$/i.test(entry.pageUrl)) score += 8;
      if (context.servicePlace && normalizeText(entry.text).includes(normalizeText(context.servicePlace))) {
        score += 20;
      }
    }

    if (context.intent === "process" && /our-process|countertops|glass|index/i.test(entry.pageUrl)) {
      score += 6;
    }

    if (context.intent === "glass") {
      if (/glass|mirror|shower|products|about/i.test(entry.pageUrl)) score += 10;
      if (/\b(glass|mirror|mirrors|shower|door|doors|enclosure|enclosures)\b/i.test(entry.text)) score += 12;
    }

    if (context.intent === "care" && /resources\.html$/i.test(entry.pageUrl)) {
      score += 8;
    }

    if ((context.intent === "phone" || context.intent === "email" || context.intent === "contact" || context.intent === "estimate") && /contact\.html$/i.test(entry.pageUrl)) {
      score += 6;
    }

    return score;
  }

  function searchKnowledge(context) {
    const scored = searchIndex
      .map((entry) => ({
        entry,
        score: scoreEntry(entry, context)
      }))
      .filter((item) => item.score > 0)
      .sort((left, right) => right.score - left.score || left.entry.replyText.length - right.entry.replyText.length);

    if (!scored.length || scored[0].score < 10) {
      return [];
    }

    const bestScore = scored[0].score;
    return dedupeBy(
      scored.filter((item) => item.score >= Math.max(10, bestScore * 0.55)),
      (item) => `${item.entry.pageUrl}|${item.entry.replyText}`
    ).slice(0, 4);
  }

  function scoreFaqEntry(entry, context) {
    let score = 0;
    const normalizedQuestion = normalizeText(context.expandedQuestion);

    if (!context.expandedTokens.length) return 0;

    if (normalizeText(entry.question) === normalizeText(context.question)) {
      score += 45;
    }

    if (normalizedQuestion && entry.normalized.includes(normalizedQuestion)) {
      score += 34;
    }

    context.baseTokens.forEach((token) => {
      if (entry.tokenSet.has(token)) {
        score += token.length >= 7 ? 8 : 6;
        return;
      }

      if (entry.normalized.includes(token)) {
        score += token.length >= 7 ? 5 : 4;
      }
    });

    context.expandedTokens.forEach((token) => {
      if (context.baseTokens.includes(token)) return;
      if (entry.tokenSet.has(token)) {
        score += 2;
      }
    });

    if (context.followUp && conversationState.lastFaqId === entry.id) {
      score += 10;
    }

    if (entry.intent === context.intent && context.intent !== "generic") {
      score += 12;
    }

    if (INTENT_PATTERNS.yesNo.test(context.question) && /^yes\b/i.test(entry.answer)) {
      score += 4;
    }

    if (context.servicePlace && entry.normalized.includes(normalizeText(context.servicePlace))) {
      score += 20;
    }

    context.materialTerms.forEach((term) => {
      const normalizedMaterial = normalizeText(term.key);
      if (entry.materialKeys.includes(normalizedMaterial)) {
        score += 10;
      } else if (entry.normalized.includes(normalizedMaterial.split(" ")[0])) {
        score += 6;
      }
    });

    if (context.intent === "estimate" && /\b(price|pricing|cost|estimate|quote|deposit|included|ballpark)\b/i.test(`${entry.question} ${entry.answer}`)) {
      score += 8;
    }

    if (context.intent === "process" && /\b(process|template|measure|fabrication|installation|cabinet|remove|start|week|day)\b/i.test(`${entry.question} ${entry.answer}`)) {
      score += 8;
    }

    if (context.intent === "glass" && /\b(glass|shower|enclosure|mirror)\b/i.test(`${entry.question} ${entry.answer}`)) {
      score += 8;
    }

    if (context.intent === "service" && /\b(serve|service area|fayetteville|pinehurst|southern pines|fort liberty)\b/i.test(`${entry.question} ${entry.answer}`)) {
      score += 8;
    }

    return score;
  }

  function searchFaq(context) {
    const scored = faqIndex
      .map((entry) => ({
        entry,
        score: scoreFaqEntry(entry, context)
      }))
      .filter((item) => item.score > 0)
      .sort((left, right) => right.score - left.score || left.entry.answer.length - right.entry.answer.length);

    if (!scored.length || scored[0].score < 12) {
      return [];
    }

    const bestScore = scored[0].score;
    return scored.filter((item) => item.score >= Math.max(12, bestScore * 0.58)).slice(0, 3);
  }

  function assessReplyConfidence(context, siteMatches, faqMatches) {
    if (context.flow) {
      return {
        level: "high",
        reason: "guided_flow",
        siteScore: 0,
        faqScore: 0
      };
    }

    const siteScore = siteMatches.length ? siteMatches[0].score : 0;
    const faqScore = faqMatches.length ? faqMatches[0].score : 0;
    const bestScore = Math.max(siteScore, faqScore);
    let level = "low";

    if (faqScore >= 42 || siteScore >= 28) {
      level = "high";
    } else if (faqScore >= 22 || siteScore >= 16) {
      level = "medium";
    }

    if (context.intent === "generic" && bestScore < 24) {
      level = "low";
    }

    if ((context.intent === "materials" || context.intent === "compare") && context.materialTerms.length && bestScore >= 18) {
      level = level === "low" ? "medium" : level;
    }

    return {
      level,
      reason: !siteMatches.length && !faqMatches.length
        ? "no_match"
        : (level === "low" ? "low_confidence" : (level === "medium" ? "needs_clarification" : "matched")),
      siteScore,
      faqScore
    };
  }

  function buildClarifyingQuestion(context) {
    if (context.intent === "care" && !context.materialTerms.length) {
      return "Which material are you looking at?";
    }

    if (context.customerType === "builder_or_contractor" && !/\b(pricing|scheduling|timeline)\b/i.test(context.question)) {
      return "Are you mainly looking for pricing, scheduling, or both?";
    }

    if ((context.intent === "materials" || context.intent === "compare") && !context.spaceType) {
      return pickReplyVariant(`${context.question}|space`, [
        "Is this for a kitchen, bathroom, outdoor kitchen, or another space?",
        "What space are you choosing this material for?"
      ]);
    }

    if ((context.intent === "materials" || context.intent === "compare") && !context.materialPriority) {
      return pickReplyVariant(`${context.question}|priority`, [
        "Is low maintenance, natural stone, or a more high-end look the bigger priority?",
        "Would you say the bigger priority is easy upkeep, natural stone, or a more high-end look?"
      ]);
    }

    if (context.intent === "estimate" && !context.serviceKey) {
      return "Is this for countertops, shower glass, or another type of project?";
    }

    if (context.intent === "glass") {
      if (!context.serviceKey) {
        return "Are you asking about shower glass, bath enclosures, mirrors, or custom cut glass?";
      }
      return "Is this for a new build or a remodel?";
    }

    if (context.intent === "process" && !context.serviceKey) {
      return "Is this process question for countertops, shower glass, or another project?";
    }

    return "Are you looking for countertops, shower glass, material options, or a quote?";
  }

  function buildConsultativeMaterialReply(context) {
    if (context.intent !== "materials" && context.intent !== "compare") {
      return "";
    }

    const priority = context.materialPriority;
    const spaceType = context.spaceType;

    if (priority === "low maintenance") {
      return "If low maintenance is the main goal, quartz is usually the best place to start. It gives you a cleaner, more consistent look and it does not need sealing.";
    }

    if (priority === "natural stone") {
      return "If natural stone is the priority, granite and quartzite are usually the first two worth comparing. Both give you real stone character, while quartzite usually has a more upscale look.";
    }

    if (priority === "a high-end look") {
      return "If you want a more high-end look, marble and quartzite are usually the strongest starting points. Marble has a softer classic feel, while quartzite gives you bold natural movement with more durability.";
    }

    if (priority === "durability") {
      return "If durability is the main concern, granite and quartzite are usually the first materials to compare. They both hold up well, and quartzite is especially strong for heavy daily use.";
    }

    if (priority === "a cleaner, more consistent look") {
      return "If you want a cleaner, more consistent look, quartz is usually the best starting point. It gives you more uniform color and pattern than natural stone.";
    }

    if (spaceType === "outdoor kitchen") {
      return "For an outdoor kitchen, natural stone options like granite or quartzite are usually the better place to start.";
    }

    if (spaceType === "bathroom") {
      return "For a bathroom, quartz, marble, and quartzite can all work well depending on whether you want lower maintenance or a more high-end stone look.";
    }

    if (spaceType === "kitchen") {
      return "For a kitchen, quartz is usually a strong fit if you want lower maintenance, while granite or quartzite are great if you want natural stone.";
    }

    return "";
  }

  function buildLayeredFollowUp(context, confidence) {
    if (confidence.level === "low" || shouldOfferLeadCapture(context.question)) {
      return "";
    }

    if (context.intent === "materials" || context.intent === "compare") {
      if (!context.spaceType) {
        return "Is this for a kitchen, bathroom, or another space?";
      }
      if (!context.materialPriority) {
        return "Is low maintenance, natural stone, or a higher-end look the bigger priority?";
      }
    }

    if (context.intent === "estimate" && !context.serviceKey) {
      return "Is this for countertops, shower glass, or another type of project?";
    }

    if (context.intent === "glass") {
      if (!context.serviceKey) {
        return "Are you asking about shower glass, bath enclosures, mirrors, or custom cut glass?";
      }
      return "Is this for a new build or a remodel?";
    }

    return "";
  }

  function buildLowConfidenceReply(context, confidence) {
    const consultativeText = buildConsultativeMaterialReply(context);
    const prompt = buildClarifyingQuestion(context);
    const text = confidence.reason === "knowledge_not_loaded"
      ? "The website knowledge base is not loaded right now, so I can’t answer from the site yet."
      : (consultativeText
      ? joinParts([finalizeSentence(consultativeText), prompt])
      : joinParts([
        pickReplyVariant(`${context.question}|fallback`, [
          "I want to make sure I point you in the right direction.",
          "I want to make sure I’m answering the right part of the project.",
          "I can help, but I want to make sure I’m focusing on the right thing."
        ]),
        prompt
      ]));

    return {
      text,
      sources: [],
      actions: shouldOfferLeadCapture(context.question) ? buildLeadActions(context.question) : [],
      suggestions: WELCOME_QUICK_REPLIES.slice(),
      meta: {
        confidence: confidence.level,
        logGap: true,
        gapReason: confidence.reason,
        intent: context.intent,
        serviceKey: context.serviceKey,
        customerType: context.customerType,
        spaceType: context.spaceType,
        materialPriority: context.materialPriority,
        materialKeys: context.materialTerms.map((term) => normalizeText(term.key))
      }
    };
  }

  function finalizeReplyPackage(context, reply, confidence) {
    if (confidence.level === "low") {
      return buildLowConfidenceReply(context, confidence);
    }

    const nextQuestion = buildLayeredFollowUp(context, confidence);
    const hasExplicitSuggestions = Array.isArray(reply.suggestions);
    const nextSuggestions = hasExplicitSuggestions
      ? reply.suggestions
      : buildSuggestions(context);
    const nextActions = (reply.actions && reply.actions.length)
      ? reply.actions
      : ((context.buyingStage === "quote_ready" || shouldOfferLeadCapture(context.question)) ? buildLeadActions(context.question) : []);
    let text = reply.text;

    if (confidence.level === "medium" && nextQuestion && !normalizeText(text).includes(normalizeText(nextQuestion))) {
      text = joinParts([finalizeSentence(text), nextQuestion]);
    }

    return {
      ...reply,
      text,
      actions: nextActions,
      suggestions: (reply.preserveSuggestions || nextSuggestions.length) ? nextSuggestions : WELCOME_QUICK_REPLIES.slice(0, 4),
      meta: {
        confidence: confidence.level,
        logGap: false,
        gapReason: confidence.reason,
        intent: context.intent,
        serviceKey: context.serviceKey,
        customerType: context.customerType,
        spaceType: context.spaceType,
        materialPriority: context.materialPriority,
        materialKeys: context.materialTerms.map((term) => normalizeText(term.key))
      }
    };
  }

  function getReplyTexts(matches) {
    return matches.map((match) => ensureSentence(match.entry.replyText)).filter(Boolean);
  }

  function getSupportingText(matches, skipText, predicate) {
    const texts = getReplyTexts(matches);
    return texts.find((text) => text !== skipText && (!predicate || predicate(text))) || "";
  }

  function extractHours(matches) {
    const combined = matches.map((match) => trimSnippetForReply(match.entry.text)).join(" ");
    const ranged = combined.match(/\bMonday\b[^.]*?\bFriday\b[^.]*?(?:am|pm)\b/i);
    if (ranged) return ranged[0].replace(/\s+/g, " ").trim();
    const single = combined.match(/\bMonday\b[^.]*?(?:am|pm)\b/i);
    return single ? single[0].replace(/\s+/g, " ").trim() : "";
  }

  function findMaterialSpecificMatch(matches, term) {
    if (!term) return matches[0];
    const materialPattern = new RegExp(`\\b${normalizeText(term.key).replace(/\s+/g, "\\s+")}\\b`, "i");
    const pagePattern = new RegExp(normalizeText(term.key).split(" ")[0], "i");
    const siteWideSpecific = searchIndex.find((entry) => (
      materialPattern.test(normalizeText(entry.replyText)) &&
      (pagePattern.test(entry.pageUrl) || pagePattern.test(entry.section) || pagePattern.test(entry.pageTitle))
    ));

    return matches.find((match) => (
      materialPattern.test(normalizeText(match.entry.replyText)) &&
      (pagePattern.test(match.entry.pageUrl) || pagePattern.test(match.entry.section) || pagePattern.test(match.entry.pageTitle))
    )) || (siteWideSpecific ? { entry: siteWideSpecific } : null) || matches.find((match) => materialPattern.test(normalizeText(match.entry.replyText))) || matches[0];
  }

  function extractAddress(matches) {
    const combined = matches.map((match) => match.entry.replyText).join(" ");
    const match = combined.match(/\b\d{3,5}\s+[A-Za-z0-9.\s]+(?:Street|St|Road|Rd|Avenue|Ave|Boulevard|Blvd|Lane|Ln)\s+Fayetteville,\s*NC\s*\d{5}\b/i);
    return match ? match[0].replace(/\s+/g, " ").trim() : "";
  }

  function extractPhone(matches) {
    const combined = matches.map((match) => match.entry.replyText).join(" ");
    const match = combined.match(PHONE_PATTERN);
    return match ? match[0].trim() : "";
  }

  function extractEmail(matches) {
    const combined = matches.map((match) => match.entry.replyText).join(" ");
    const match = combined.match(EMAIL_PATTERN);
    return match ? match[0].trim() : "";
  }

  function buildSuggestions(context) {
    if (context.customerType && CHAT_PLAYBOOK.customerTypes[context.customerType]) {
      return CHAT_PLAYBOOK.customerTypes[context.customerType].followUpQuestions.slice(0, 2);
    }

    if (CHAT_PLAYBOOK.nextStepOptions[context.intent]) {
      return CHAT_PLAYBOOK.nextStepOptions[context.intent].slice(0, 2);
    }

    switch (context.intent) {
      case "hours":
        return ["Where are you located?", "Do you offer free estimates?"];
      case "location":
        return ["What are your hours?", "What areas do you serve?"];
      case "estimate":
        return ["What materials do you offer?", "How does the process work?"];
      case "service":
        return ["Do you offer free estimates?", "What materials do you offer?"];
      case "materials":
      case "compare":
        return ["Do you offer free estimates?", "How does the installation process work?"];
      case "process":
        return ["Do you offer free estimates?", "What materials do you offer?"];
      case "care":
        return ["What materials do you offer?", "Do you offer free estimates?"];
      default:
        return [];
    }
  }

  function buildFallbackReply(context) {
    const fallbackText = pickReplyVariant(`${context.question}|fallback-base`, [
      CHAT_PLAYBOOK.fallbackResponse,
      "I’m happy to help. Tell me a little more about what you’re working on.",
      "I can help with that. I just want to make sure I’m focusing on the right part of the project."
    ]);

    return {
      text: finalizeSentence(fallbackText),
      sources: [],
      actions: shouldOfferLeadCapture(context.question) ? buildLeadActions(context.question) : [],
      suggestions: WELCOME_QUICK_REPLIES.slice(),
      meta: {
        confidence: "low",
        logGap: true,
        gapReason: "no_match"
      }
    };
  }

  function buildFlowReply(context) {
    const flow = context.flow;
    if (!flow) return null;
    const flowStartsLead = Boolean(flow.startLeadFlow) || flow.key === "quote" || flow.key === "shower" || flow.key === "ready";
    const text = flowStartsLead ? buildLeadFlowIntroText(context) : flow.opening;

    return {
      text: finalizeSentence(text),
      sources: [],
      actions: flowStartsLead ? buildLeadActions(context.question).filter((action) => action.action === "open-lead-form") : (flow.offerLead ? buildLeadActions(context.question) : []),
      suggestions: flowStartsLead ? [] : (flow.suggestions && flow.suggestions.length ? flow.suggestions.slice(0, 3) : buildSuggestions(context)),
      preserveSuggestions: flowStartsLead,
      startLeadFlow: flowStartsLead,
      leadProjectType: formProjectTypeValue(context.serviceKey)
    };
  }

  function isRelevantSupportText(context, text) {
    const normalized = normalizeText(text);
    const normalizedQuestion = normalizeText(context.question);

    if (!normalized) return false;
    if (context.intent === "contact" || context.intent === "location" || context.intent === "phone" || context.intent === "email") {
      return false;
    }
    if (context.intent === "glass") return INTENT_PATTERNS.glass.test(text);
    if (context.intent === "service" && context.servicePlace) {
      return normalized.includes(normalizeText(context.servicePlace)) || /\b(service|serve|coverage|radius)\b/i.test(text);
    }
    if (context.intent === "materials" || context.intent === "compare") {
      return context.materialTerms.some((term) => normalized.includes(normalizeText(term.key).split(" ")[0]));
    }
    if (context.intent === "estimate") {
      return /\b(estimate|quote|price|pricing|cost|deposit|budget|ballpark|included)\b/i.test(text);
    }
    if (context.intent === "process") {
      if (/\binstall|installation\b/.test(normalizedQuestion) && !/\bprocess\b/.test(normalizedQuestion)) {
        return /\b(install|installation)\b/i.test(text) && /\b(hour|hours|day|days)\b/i.test(text) && !/\btemplate|measure\b/i.test(text);
      }
      if (/\btemplate|templating|measure|measuring\b/.test(normalizedQuestion)) {
        return /\b(template|templating|measure|measurement|proliner)\b/i.test(text);
      }
      if (/\bremove|removal|old countertop\b/.test(normalizedQuestion)) {
        return /\b(remove|removal|disposal|old countertop)\b/i.test(text);
      }
      return /\b(process|template|templating|measure|fabrication|installation|install|cabinet|remove|day|week)\b/i.test(text);
    }
    if (context.intent === "care") {
      return /\b(clean|cleaning|care|maintenance|seal|stain|heat|trivet)\b/i.test(text);
    }

    return false;
  }

  function isRelevantFaqSupport(context, entry) {
    const combined = `${entry.question} ${entry.answer}`;
    const normalized = normalizeText(combined);

    if (!normalized || context.intent === "generic") return false;
    if (context.intent === "contact" || context.intent === "location" || context.intent === "phone" || context.intent === "email") {
      return false;
    }
    if (entry.intent === context.intent) return true;
    if (context.materialTerms.length) {
      return context.materialTerms.some((term) => entry.materialKeys.includes(normalizeText(term.key)));
    }
    if (context.servicePlace) {
      return normalized.includes(normalizeText(context.servicePlace));
    }

    return isRelevantSupportText(context, combined);
  }

  function buildFaqSuggestions(context, faqMatch) {
    const configuredFaqSuggestions = dedupeBy(
      [
        ...(faqMatch.entry.nextStepOptions || []),
        faqMatch.entry.followUp || ""
      ].filter(Boolean),
      (question) => normalizePhrase(question)
    );

    if (configuredFaqSuggestions.length) {
      return configuredFaqSuggestions.slice(0, 2);
    }

    const intentSuggestions = buildSuggestions(context);
    if (intentSuggestions.length) {
      return intentSuggestions;
    }

    const relatedQuestions = faqIndex
      .filter((entry) => entry.id !== faqMatch.entry.id)
      .filter((entry) => {
        if (context.intent !== "generic" && entry.intent === context.intent) return true;
        if (context.materialTerms.length) {
          return context.materialTerms.some((term) => entry.materialKeys.includes(normalizeText(term.key)));
        }
        return false;
      })
      .map((entry) => entry.question)
      .slice(0, 2);

    return dedupeBy(
      [...relatedQuestions, ...QUICK_QUESTIONS],
      (question) => question
    ).filter((question) => normalizeText(question) !== normalizeText(faqMatch.entry.question)).slice(0, 4);
  }

  function shouldPreferFaq(context, faqMatches, siteMatches) {
    if (!faqMatches.length) return false;
    if (!siteMatches.length) return true;

    const faqBest = faqMatches[0];
    const siteBest = siteMatches[0];

    if (context.intent === "hours" || context.intent === "location" || context.intent === "email") {
      return false;
    }

    if (context.intent === "phone" && PHONE_PATTERN.test(siteBest.entry.replyText)) {
      return false;
    }

    if (context.intent === "service" && context.servicePlace) {
      const normalizedPlace = normalizeText(context.servicePlace);
      const faqConfirmsPlace = BUSINESS_PROFILE.serviceArea.some((area) => normalizeText(area) === normalizedPlace) ||
        faqBest.entry.normalized.includes(normalizedPlace);
      const siteConfirmsPlace = siteMatches.some((match) => normalizeText(match.entry.replyText).includes(normalizedPlace));

      if (faqConfirmsPlace && !siteConfirmsPlace) {
        return true;
      }
    }

    if (context.intent === "generic") {
      return faqBest.score >= 16;
    }

    return faqBest.score >= siteBest.score - 4;
  }

  function buildFaqReply(context, faqMatches, siteMatches) {
    const primaryFaq = faqMatches[0];
    const secondaryFaqMatch = faqMatches.slice(1).find((match) => isRelevantFaqSupport(context, match.entry));
    const allowFaqSupport = !/^(yes|no)\b/i.test(primaryFaq.entry.answer);
    const secondaryFaq = allowFaqSupport && secondaryFaqMatch ? finalizeSentence(secondaryFaqMatch.entry.answer) : "";
    const supportingSiteText = siteMatches.length && primaryFaq.entry.sourceType !== "playbook"
      ? getSupportingText(siteMatches, "", (text) => isRelevantSupportText(context, text))
      : "";
    const sources = dedupeBy(
      siteMatches.map((match) => ({
        label: match.entry.pageName,
        url: match.entry.pageUrl
      })),
      (source) => source.url
    );
    const suggestions = buildFaqSuggestions(context, primaryFaq);
    const actions = shouldOfferLeadCapture(context.question) || /contact me|move forward|get started|quote|estimate|ready/i.test(`${primaryFaq.entry.question} ${primaryFaq.entry.answer}`)
      ? buildLeadActions(context.question || primaryFaq.entry.question)
      : [];

    let text = context.intent === "contact"
      ? buildTalkToSomeoneText()
      : finalizeSentence(primaryFaq.entry.answer);

    if (context.intent === "materials" && context.materialTerms.length === 1) {
      const profileAnswer = buildSingleMaterialProfileAnswer(context.materialTerms[0]);
      if (profileAnswer) {
        text = finalizeSentence(profileAnswer);
      }
    }

    if (context.intent === "compare") {
      const comparisonAnswer = buildMaterialComparisonAnswer(context.question, context.materialTerms);
      if (comparisonAnswer) {
        text = finalizeSentence(comparisonAnswer);
      }
    }

    if (context.intent === "service" && context.servicePlace) {
      const place = titleCase(context.servicePlace);
      const isKnownArea = BUSINESS_PROFILE.serviceArea.some((area) => normalizeText(area) === normalizeText(place));
      if (isKnownArea && !/^yes\b/i.test(text)) {
        text = `Yes, ${place} is in our service area. ${text}`;
      }
    }

    if (context.intent === "phone" && !PHONE_PATTERN.test(text)) {
      text = `You can call ${BUSINESS_PROFILE.businessName} at ${BUSINESS_PROFILE.phone}.`;
    }

    const consultativeMaterialText = buildConsultativeMaterialReply(context);
    if (consultativeMaterialText && (context.materialPriority || context.spaceType) && !normalizeText(text).includes(normalizeText(consultativeMaterialText))) {
      text = joinParts([finalizeSentence(text), consultativeMaterialText]);
    }

    const supportingText = secondaryFaq || supportingSiteText;
    const trimmedSupport = trimSentenceEnding(supportingText);
    if (trimmedSupport && !normalizeText(text).includes(normalizeText(trimmedSupport))) {
      if (secondaryFaq) {
        text = joinParts([finalizeSentence(text), `Also, ${lowercaseFirst(trimmedSupport)}.`]);
      } else {
        text = joinParts([
          finalizeSentence(text),
          pickReplyVariant(`${context.question}|faq-support`, [
            `Another helpful point is ${lowercaseFirst(trimmedSupport)}.`,
            `It also helps to know that ${lowercaseFirst(trimmedSupport)}.`,
            `One other detail is that ${lowercaseFirst(trimmedSupport)}.`
          ])
        ]);
      }
    } else {
      text = finalizeSentence(text);
    }

    return {
      text,
      sources,
      actions,
      suggestions
    };
  }

  function buildHumanReply(context, matches) {
    const sources = dedupeBy(
      matches.map((match) => ({
        label: match.entry.pageName,
        url: match.entry.pageUrl
      })),
      (source) => source.url
    );

    const primary = ensureSentence(matches[0].entry.replyText);
    const supporting = getSupportingText(matches, primary, (text) => {
      if (context.intent === "glass") return INTENT_PATTERNS.glass.test(text);
      if (context.intent === "materials" && context.materialTerms.length === 1) {
        const material = normalizeText(context.materialTerms[0].key);
        return normalizeText(text).includes(material.split(" ")[0]);
      }
      if (context.intent === "service" && context.servicePlace) {
        return normalizeText(text).includes(normalizeText(context.servicePlace));
      }
      return true;
    });
    const actions = shouldOfferLeadCapture(context.question) ? buildLeadActions(context.question) : [];
    const suggestions = buildSuggestions(context);

    let text = primary;

    if (context.intent === "hours") {
      const globalHourEntry = searchIndex.find((entry) => /\bMonday\b/i.test(entry.text) && /\bFriday\b/i.test(entry.text) && /\b(am|pm)\b/i.test(entry.text));
      const hourEntry = globalHourEntry ? { entry: globalHourEntry } : matches.find((match) => /\bMonday\b/i.test(match.entry.text));
      let hours = "";
      if (hourEntry) {
        const rawHoursText = cleanSnippet(hourEntry.entry.text);
        const label = "Our Showroom:";
        const labelIndex = rawHoursText.indexOf(label);
        hours = (labelIndex === -1 ? rawHoursText : rawHoursText.slice(labelIndex + label.length)).trim();
      } else {
        hours = extractHours(matches);
      }
      text = hours
        ? `The hours listed on the site are ${hours.replace(/[.!?]+$/g, "")}.`
        : `The hours listed on the site are ${trimSentenceEnding(matches[0].entry.replyText)}.`;
    } else if (context.intent === "location") {
      const address = extractAddress(matches);
      text = address
        ? `The showroom is listed at ${address}.`
        : finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.intent === "phone") {
      const phone = extractPhone(matches);
      text = phone
        ? `You can call Olive Glass & Marble at ${phone}.`
        : finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.intent === "email") {
      const email = extractEmail(matches);
      text = email
        ? `You can email the team at ${email}.`
        : finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.intent === "contact") {
      const phone = extractPhone(matches);
      const email = extractEmail(matches);
      const parts = [];
      if (phone) parts.push(`call ${phone}`);
      if (email) parts.push(`email ${email}`);
      text = parts.length
        ? `${buildTalkToSomeoneText()} You can also ${parts.join(" or ")}.`
        : buildTalkToSomeoneText();
    } else if (context.intent === "estimate") {
      text = /^yes[, ]/i.test(trimSnippetForReply(matches[0].entry.replyText))
        ? `${ensureSentence(trimSnippetForReply(matches[0].entry.replyText))}`
        : finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.intent === "service") {
      const place = context.servicePlace ? titleCase(context.servicePlace) : "";
      const confirmed = place && matches.some((match) => normalizeText(match.entry.replyText).includes(normalizeText(place)));
      if (place && confirmed) {
        text = `Yes, ${place} is included in the service area.`;
      } else if (place) {
        text = `I could not confirm ${place} specifically. ${ensureSentence(matches[0].entry.replyText)}`;
      } else {
        text = finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
      }
    } else if (context.intent === "glass") {
      text = INTENT_PATTERNS.yesNo.test(context.question)
        ? `Yes, ${trimSentenceEnding(matches[0].entry.replyText)}.`
        : finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.intent === "materials") {
      const materialMatch = findMaterialSpecificMatch(matches, context.materialTerms[0]);
      if (context.materialTerms.length === 1 && INTENT_PATTERNS.yesNo.test(context.question)) {
        text = `Yes, ${context.materialTerms[0].label} is one of the materials offered.`;
      } else if (context.materialTerms.length === 1) {
        const profileAnswer = buildSingleMaterialProfileAnswer(context.materialTerms[0]);
        text = profileAnswer
          ? finalizeSentence(profileAnswer)
          : `${capitalizeFirst(context.materialTerms[0].label)} is described this way: ${trimSentenceEnding(materialMatch.entry.replyText)}.`;
      } else {
        text = finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
      }
    } else if (context.intent === "compare") {
      const comparisonAnswer = buildMaterialComparisonAnswer(context.question, context.materialTerms);
      const labels = context.materialTerms.map((term) => term.label);
      if (comparisonAnswer) {
        text = finalizeSentence(comparisonAnswer);
      } else if (labels.length >= 2) {
        text = `${capitalizeFirst(labels.slice(0, 2).join(" and "))} compare this way: ${trimSentenceEnding(matches[0].entry.replyText)}.`;
      } else {
        text = finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
      }
    } else if (context.intent === "process") {
      text = `The process is ${trimSentenceEnding(matches[0].entry.replyText)}.`;
    } else if (context.intent === "care") {
      text = finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    } else if (context.followUp) {
      text = finalizeSentence(trimSentenceEnding(matches[0].entry.replyText));
    }

    const consultativeMaterialText = buildConsultativeMaterialReply(context);
    if (consultativeMaterialText && (context.materialPriority || context.spaceType) && !normalizeText(text).includes(normalizeText(consultativeMaterialText))) {
      text = joinParts([finalizeSentence(text), consultativeMaterialText]);
    }

    if (supporting && context.intent !== "phone" && context.intent !== "email" && context.intent !== "contact" && context.intent !== "location") {
      const shortSupport = trimSentenceEnding(supporting);
      if (shortSupport && !normalizeText(text).includes(normalizeText(shortSupport))) {
        text = joinParts([
          finalizeSentence(text),
          pickReplyVariant(`${context.question}|support`, [
            `Another helpful detail is that ${shortSupport.charAt(0).toLowerCase()}${shortSupport.slice(1)}.`,
            `It also helps to know that ${shortSupport.charAt(0).toLowerCase()}${shortSupport.slice(1)}.`,
            `It also looks like ${shortSupport.charAt(0).toLowerCase()}${shortSupport.slice(1)}.`
          ])
        ]);
      }
    } else {
      text = finalizeSentence(text);
    }

    return {
      text,
      sources,
      actions,
      suggestions
    };
  }

  function updateConversationMemory(context, confidence) {
    if (context.materialTerms.length) {
      conversationState.lastMaterialKeys = dedupeBy(
        context.materialTerms.map((term) => normalizeText(term.key)),
        (key) => key
      ).slice(0, 4);
    }

    if (context.serviceKey) {
      conversationState.serviceKey = context.serviceKey;
    }

    if (context.customerType) {
      conversationState.customerType = context.customerType;
    }

    if (context.spaceType) {
      conversationState.spaceType = context.spaceType;
    }

    if (context.materialPriority) {
      conversationState.materialPriority = context.materialPriority;
    }

    if (context.buyingStage) {
      conversationState.buyingStage = context.buyingStage;
    }

    if (confidence && confidence.level) {
      conversationState.lastConfidence = confidence.level;
    }
  }

  function updateConversationState(context, matches, faqMatches, confidence) {
    const sourcePages = dedupeBy(matches.map((match) => match.entry.pageUrl), (pageUrl) => pageUrl);
    const materialKeys = context.materialTerms.map((term) => normalizeText(term.key));
    const faqTokens = faqMatches.length
      ? tokenize(`${faqMatches[0].entry.question} ${faqMatches[0].entry.answer}`).slice(0, 6)
      : [];
    const nextContextTokens = dedupeBy(
      [
        ...context.baseTokens,
        ...materialKeys,
        ...faqTokens,
        ...sourcePages.map((page) => page.replace(/\.html$/i, ""))
      ],
      (token) => token
    ).slice(0, 10);

    conversationState.lastQuestion = context.question;
    conversationState.lastIntent = context.intent;
    conversationState.lastContextTokens = nextContextTokens;
    conversationState.lastSourcePages = sourcePages;
    conversationState.lastFaqId = faqMatches.length ? faqMatches[0].entry.id : null;
    updateConversationMemory(context, confidence);
  }

  function updateConversationStateFromFlow(context, confidence) {
    const flow = context.flow;
    const nextContextTokens = dedupeBy(
      [
        ...context.baseTokens,
        ...(flow ? flow.contextTokens : [])
      ],
      (token) => token
    ).slice(0, 10);

    conversationState.lastQuestion = context.question;
    conversationState.lastIntent = flow ? flow.intent : context.intent;
    conversationState.lastContextTokens = nextContextTokens;
    conversationState.lastSourcePages = [];
    conversationState.lastFaqId = null;
    updateConversationMemory(context, confidence);
  }

  function buildReply(question) {
    const context = buildSearchContext(question);
    const emptyConfidence = {
      level: "low",
      reason: "knowledge_not_loaded",
      siteScore: 0,
      faqScore: 0
    };

    if (!searchIndex.length && !faqIndex.length) {
      return buildLowConfidenceReply(context, emptyConfidence);
    }

    if (context.incompletePrompt) {
      const confidence = {
        level: "low",
        reason: "incomplete_prompt",
        siteScore: 0,
        faqScore: 0
      };
      updateConversationMemory(context, confidence);
      return buildLowConfidenceReply(context, confidence);
    }

    if (context.flow) {
      const confidence = assessReplyConfidence(context, [], []);
      updateConversationStateFromFlow(context, confidence);
      return finalizeReplyPackage(context, buildFlowReply(context), confidence);
    }

    const siteMatches = searchKnowledge(context);
    const faqMatches = searchFaq(context);
    const confidence = assessReplyConfidence(context, siteMatches, faqMatches);

    if (!siteMatches.length && !faqMatches.length) {
      updateConversationMemory(context, confidence);
      return finalizeReplyPackage(context, buildFallbackReply(context), confidence);
    }

    if (shouldPreferFaq(context, faqMatches, siteMatches)) {
      updateConversationState(context, siteMatches, faqMatches, confidence);
      return finalizeReplyPackage(context, buildFaqReply(context, faqMatches, siteMatches), confidence);
    }

    updateConversationState(context, siteMatches, faqMatches, confidence);
    return finalizeReplyPackage(context, buildHumanReply(context, siteMatches), confidence);
  }

  function resetLeadCaptureState(options) {
    leadCaptureState.active = false;
    leadCaptureState.sourceQuestion = "";
    leadCaptureState.currentStepKey = "";
    leadCaptureState.answers = {};

    if (!options || options.persist !== false) {
      persistChatSessionState();
    }
  }

  function buildLeadStep(key, prompt, options) {
    return {
      key,
      prompt,
      options: options || []
    };
  }

  function serviceKeyFromProjectTypeValue(projectType) {
    switch (projectType) {
      case "countertops":
        return "countertops";
      case "shower-doors":
        return "shower_glass";
      case "mirrors":
        return "custom_glass";
      default:
        return "";
    }
  }

  function getLeadFlowSteps(answers, sourceQuestion) {
    const serviceKey = answers.serviceKey || inferServiceKey(answers.projectType) || "";
    const customerType = answers.customerType || "";
    const steps = [];
    addLeadIdentitySteps(steps, answers);

    if (!answers.projectType) {
      steps.push(buildLeadStep("projectType", "What type of project is this for?", [
        "Countertops",
        "Shower Glass",
        "Custom Glass / Mirrors",
        "Something Else"
      ]));
    }

    if (serviceKey === "shower_glass") {
      addShowerGlassLeadSteps(steps, answers);
    } else if (serviceKey === "custom_glass") {
      addCustomGlassLeadSteps(steps, answers);
    } else if (serviceKey === "countertops") {
      addCountertopLeadSteps(steps, answers, customerType);
    }

    if (answers.needsCustomCity) {
      steps.push(buildLeadStep("cityCustom", "Please type the city name."));
    } else if (!answers.city) {
      steps.push(buildLeadStep("city", "What city is the project in?", [
        "Fayetteville",
        "Pinehurst",
        "Hope Mills",
        "Spring Lake",
        "Other"
      ]));
    }

    if (!answers.timeline) {
      steps.push(buildLeadStep("timeline", "What kind of timeline are you working with?", [
        "ASAP",
        "In the next few weeks",
        "Just planning"
      ]));
    }

    return steps;
  }

  function getCurrentLeadStep() {
    return getLeadFlowSteps(leadCaptureState.answers, leadCaptureState.sourceQuestion)[0] || null;
  }

  function parseContactDetails(value) {
    const text = value || "";
    const phoneMatch = text.match(PHONE_PATTERN);
    const emailMatch = text.match(EMAIL_PATTERN);

    return {
      phone: phoneMatch ? phoneMatch[0].trim() : "",
      email: emailMatch ? emailMatch[0].trim() : ""
    };
  }

  function extractLeadName(value, contactDetails) {
    return String(value || "")
      .replace(contactDetails.phone || "", " ")
      .replace(contactDetails.email || "", " ")
      .replace(/\s+/g, " ")
      .replace(/^[,;:/\-\s]+|[,;:/\-\s]+$/g, "")
      .trim();
  }

  function looksLikeProjectIntent(text) {
    const normalized = normalizePhrase(text);
    if (!normalized) return false;
    if (QUOTE_READY_PATTERN.test(normalized)) return true;
    if (isDirectPurchaseLeadIntent(normalized)) return true;
    if (inferServiceKey(normalized)) return true;
    return /\b(project|countertop|countertops|shower|glass|enclosure|door|mirror|mirrors|quote|pricing|estimate|remodel|new build|new construction)\b/i.test(normalized);
  }

  function canUseAsLeadName(answer) {
    const trimmed = (answer || "").trim();
    if (!trimmed || /\?$/.test(trimmed)) {
      return false;
    }

    const contactDetails = parseContactDetails(trimmed);
    const extractedName = extractLeadName(trimmed, contactDetails);
    if (looksLikeProjectIntent(extractedName || trimmed)) {
      return false;
    }

    if (contactDetails.phone || contactDetails.email) {
      return Boolean(extractedName);
    }

    if (/[0-9@]/.test(trimmed)) {
      return false;
    }

    if (/^(i|we|my|our|this|that|it|need|want|looking|quote|pricing|estimate|remodel|new|yes|no|maybe)\b/i.test(normalizePhrase(trimmed))) {
      return false;
    }

    return trimmed.split(/\s+/).filter(Boolean).length <= 4;
  }

  function canUseAsLeadContact(answer) {
    const trimmed = (answer || "").trim();
    if (!trimmed) {
      return false;
    }

    if (looksLikeLeadInfo(trimmed)) {
      return true;
    }

    return false;
  }

  function buildPartialLeadSignature(payload) {
    return [
      payload.session_id || "",
      payload.name || "",
      payload.phone || "",
      payload.email || "",
      payload.project_type || "",
      payload.city || "",
      payload.space_type || "",
      payload.material_interest || "",
      payload.build_type || "",
      payload.timeline || "",
      payload.chat_summary || "",
      payload.chat_transcript || ""
    ].join("|").toLowerCase();
  }

  function sendPartialLeadCapture(payload, options) {
    if (!payload || (!payload.phone && !payload.email) || window.location.protocol === "file:") {
      return;
    }

    const signature = buildPartialLeadSignature(payload);
    if (!signature || signature === lastPartialLeadSignature) {
      return;
    }

    const doSend = () => {
      lastPartialLeadSignature = signature;

      try {
        const body = JSON.stringify(payload);
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: "application/json" });
          navigator.sendBeacon(PARTIAL_LEAD_ENDPOINT, blob);
          return;
        }

        fetch(PARTIAL_LEAD_ENDPOINT, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json"
          },
          body,
          keepalive: true
        }).catch(() => {});
      } catch (error) {
        // Partial capture should never interrupt the chat experience.
      }
    };

    window.clearTimeout(partialLeadSaveTimer);
    if (options && options.immediate) {
      doSend();
      return;
    }

    partialLeadSaveTimer = window.setTimeout(doSend, 700);
  }

  function buildPartialLeadPayload(base) {
    return {
      session_id: chatSessionId,
      capture_source: base.captureSource || "chatbot_partial",
      source: "Homepage Chatbot Partial Lead",
      question: base.question || "",
      name: (base.name || "").trim(),
      email: (base.email || "").trim(),
      phone: (base.phone || "").trim(),
      project_type: (base.projectType || "").trim(),
      city: (base.city || "").trim(),
      space_type: (base.spaceType || "").trim(),
      material_interest: (base.materialInterest || "").trim(),
      build_type: (base.buildType || "").trim(),
      timeline: (base.timeline || "").trim(),
      chat_summary: (base.chatSummary || "").trim(),
      customer_type: (base.customerType || "").trim(),
      measurements: (base.measurements || "").trim(),
      tile_complete: (base.tileComplete || "").trim(),
      project_scope: (base.projectScope || "").trim(),
      plans_ready: (base.plansReady || "").trim(),
      pricing_or_scheduling: (base.pricingOrScheduling || "").trim(),
      home_or_commercial: (base.homeOrCommercial || "").trim(),
      chat_transcript: (base.chatTranscript || buildChatTranscriptText()).trim(),
      page_url: window.location.href,
      timestamp: new Date().toISOString()
    };
  }

  function savePartialLeadFromAnswers(captureSource, options) {
    const prefill = buildLeadPrefillFromAnswers();
    sendPartialLeadCapture(buildPartialLeadPayload({
      captureSource,
      question: leadCaptureState.sourceQuestion || conversationState.lastQuestion || "",
      name: prefill.name,
      email: prefill.email,
      phone: prefill.phone,
      projectType: prefill.projectType,
      city: prefill.city,
      spaceType: prefill.spaceType,
      materialInterest: prefill.materialInterest,
      buildType: prefill.buildType,
      timeline: prefill.timeline,
      chatSummary: prefill.chatSummary,
      customerType: prefill.customerType,
      measurements: prefill.measurements,
      tileComplete: prefill.tileComplete,
      projectScope: prefill.projectScope,
      plansReady: prefill.plansReady,
      pricingOrScheduling: prefill.pricingOrScheduling,
      homeOrCommercial: prefill.homeOrCommercial,
      chatTranscript: buildChatTranscriptText()
    }), options || {});
  }

  function buildPartialLeadPayloadFromForm(leadForm, captureSource) {
    const readField = (name) => {
      const field = leadForm.querySelector(`[name="${name}"]`);
      return field ? field.value.trim() : "";
    };

    return buildPartialLeadPayload({
      captureSource,
      question: readField("chat-summary").replace(/^Original chat request:\s*/i, "").split("\n")[0] || conversationState.lastQuestion || "",
      name: readField("name"),
      email: readField("email"),
      phone: readField("phone"),
      projectType: readField("project-type"),
      city: readField("city"),
      spaceType: readField("space-type"),
      materialInterest: readField("material-interest"),
      buildType: readField("build-type"),
      timeline: readField("timeline"),
      chatSummary: readField("chat-summary"),
      customerType: readField("customer-type"),
      measurements: readField("measurements"),
      tileComplete: readField("tile-complete"),
      projectScope: readField("project-scope"),
      plansReady: readField("plans-ready"),
      pricingOrScheduling: readField("pricing-or-scheduling"),
      homeOrCommercial: readField("home-or-commercial"),
      chatTranscript: buildChatTranscriptText()
    });
  }

  function attachLeadFormAutosave(leadForm) {
    if (!leadForm || leadForm.dataset.partialCaptureBound === "true") {
      return;
    }

    leadForm.dataset.partialCaptureBound = "true";

    const queueSave = (options) => {
      if (leadForm.dataset.completed === "true") {
        return;
      }

      sendPartialLeadCapture(
        buildPartialLeadPayloadFromForm(leadForm, (options && options.captureSource) || "lead_form_draft"),
        options || {}
      );
    };

    leadForm.addEventListener("input", () => {
      queueSave({ captureSource: "lead_form_draft" });
    });

    leadForm.addEventListener("change", () => {
      queueSave({ captureSource: "lead_form_change" });
    });

    leadForm.addEventListener("focusout", (event) => {
      if (!event.target || !event.target.name || !/^(name|phone|email)$/.test(event.target.name)) {
        return;
      }

      queueSave({ captureSource: `lead_form_${event.target.name}`, immediate: true });
    });
  }

  function storeLeadAnswer(step, rawAnswer) {
    const answer = rawAnswer.trim();
    const normalized = normalizeText(answer);

    if (!leadCaptureState.answers.customerType) {
      leadCaptureState.answers.customerType = detectCustomerType(answer);
    }

    switch (step.key) {
      case "projectType": {
        const serviceKey = inferServiceKey(answer);
        leadCaptureState.answers.serviceKey = serviceKey || leadCaptureState.answers.serviceKey || "";
        leadCaptureState.answers.projectType = projectTypeFromServiceKey(serviceKey) || titleCase(answer);
        break;
      }
      case "city":
        if (normalized === "other") {
          leadCaptureState.answers.city = "";
          leadCaptureState.answers.needsCustomCity = true;
          break;
        }

        leadCaptureState.answers.city = titleCase(answer);
        leadCaptureState.answers.needsCustomCity = false;
        break;
      case "cityCustom":
        leadCaptureState.answers.city = titleCase(answer);
        leadCaptureState.answers.needsCustomCity = false;
        break;
      case "buildType":
        leadCaptureState.answers.buildType = answer;
        break;
      case "spaceType":
        leadCaptureState.answers.spaceType = answer;
        break;
      case "materialInterest":
        leadCaptureState.answers.materialInterest = answer;
        break;
      case "measurements":
        leadCaptureState.answers.measurements = answer;
        break;
      case "tileComplete":
        leadCaptureState.answers.tileComplete = answer;
        break;
      case "glassProjectType":
        leadCaptureState.answers.glassProjectType = answer;
        break;
      case "homeOrCommercial":
        leadCaptureState.answers.homeOrCommercial = answer;
        if (!leadCaptureState.answers.customerType && /commercial/i.test(normalized)) {
          leadCaptureState.answers.customerType = "builder_or_contractor";
        }
        break;
      case "projectScope":
        leadCaptureState.answers.projectScope = answer;
        break;
      case "plansReady":
        leadCaptureState.answers.plansReady = answer;
        break;
      case "pricingOrScheduling":
        leadCaptureState.answers.pricingOrScheduling = answer;
        break;
      case "replacingExisting":
        leadCaptureState.answers.replacingExisting = answer;
        break;
      case "timeline":
        leadCaptureState.answers.timeline = answer;
        break;
      case "name": {
        const contactDetails = parseContactDetails(answer);
        const extractedName = extractLeadName(answer, contactDetails);

        if (extractedName) {
          leadCaptureState.answers.name = extractedName;
        }
        if (contactDetails.phone) {
          leadCaptureState.answers.phone = contactDetails.phone;
        }
        if (contactDetails.email) {
          leadCaptureState.answers.email = contactDetails.email;
        }
        if (contactDetails.phone || contactDetails.email) {
          leadCaptureState.answers.contact = [contactDetails.phone, contactDetails.email].filter(Boolean).join(" / ");
          savePartialLeadFromAnswers("lead_flow_contact", { immediate: true });
        } else if (leadCaptureState.answers.contact) {
          savePartialLeadFromAnswers("lead_flow_name", { immediate: true });
        }
        break;
      }
      case "contact": {
        leadCaptureState.answers.contact = answer;
        const contactDetails = parseContactDetails(answer);
        const extractedName = !leadCaptureState.answers.name ? extractLeadName(answer, contactDetails) : "";
        if (extractedName) {
          leadCaptureState.answers.name = extractedName;
        }
        if (contactDetails.phone) {
          leadCaptureState.answers.phone = contactDetails.phone;
        }
        if (contactDetails.email) {
          leadCaptureState.answers.email = contactDetails.email;
        }
        if (!contactDetails.phone && !contactDetails.email && !leadCaptureState.answers.name) {
          leadCaptureState.answers.contact = "";
          leadCaptureState.answers.name = answer;
          break;
        }
        savePartialLeadFromAnswers("lead_flow_contact", { immediate: true });
        break;
      }
      default:
        leadCaptureState.answers[step.key] = answer;
        break;
    }
  }

  function buildLeadSummaryMessage(answers, sourceQuestion) {
    const lines = [];

    if (sourceQuestion) lines.push(`Original chat request: ${sourceQuestion}`);
    if (answers.projectType) lines.push(`Project type: ${answers.projectType}`);
    if (answers.city) lines.push(`City: ${answers.city}`);
    if (answers.spaceType) lines.push(`Space: ${answers.spaceType}`);
    if (answers.glassProjectType) lines.push(`Glass project: ${answers.glassProjectType}`);
    if (answers.materialInterest) lines.push(`Material interest: ${answers.materialInterest}`);
    if (answers.buildType) lines.push(`Project type detail: ${answers.buildType}`);
    if (answers.replacingExisting) lines.push(`Existing countertops: ${answers.replacingExisting}`);
    if (answers.measurements) lines.push(`Measurements or plans: ${answers.measurements}`);
    if (answers.tileComplete) lines.push(`Tile complete: ${answers.tileComplete}`);
    if (answers.projectScope) lines.push(`Project scope: ${answers.projectScope}`);
    if (answers.plansReady) lines.push(`Plans ready: ${answers.plansReady}`);
    if (answers.pricingOrScheduling) lines.push(`Needs: ${answers.pricingOrScheduling}`);
    if (answers.homeOrCommercial) lines.push(`Project setting: ${answers.homeOrCommercial}`);
    if (answers.timeline) lines.push(`Timeline: ${answers.timeline}`);
    if (answers.customerType === "builder_or_contractor") lines.push("Customer type: Builder / Contractor");
    if (answers.customerType === "homeowner") lines.push("Customer type: Homeowner");

    return lines.join("\n");
  }

  function buildLeadPrefillFromAnswers() {
    const answers = leadCaptureState.answers;
    const contact = parseContactDetails(answers.contact || "");
    const details = buildLeadSummaryMessage(answers, leadCaptureState.sourceQuestion);

    return {
      projectType: formProjectTypeValue(answers.serviceKey || serviceKeyFromProjectTypeValue(detectProjectType(answers.projectType || ""))),
      city: answers.city || "",
      spaceType: answers.spaceType || answers.glassProjectType || "",
      materialInterest: answers.materialInterest || "",
      buildType: answers.buildType || "",
      timeline: answers.timeline || "",
      name: answers.name || "",
      phone: contact.phone || answers.phone || "",
      email: contact.email || answers.email || "",
      customerType: answers.customerType || "",
      measurements: answers.measurements || "",
      tileComplete: answers.tileComplete || "",
      projectScope: answers.projectScope || "",
      plansReady: answers.plansReady || "",
      pricingOrScheduling: answers.pricingOrScheduling || "",
      homeOrCommercial: answers.homeOrCommercial || "",
      chatSummary: details || (leadCaptureState.sourceQuestion ? `Chat inquiry: ${leadCaptureState.sourceQuestion}` : ""),
      prefillMessage: ""
    };
  }

  function askNextLeadQuestion() {
    const step = getCurrentLeadStep();
    if (!step) {
      const prefill = buildLeadPrefillFromAnswers();
      prefill.introText = "Thanks. I've gathered the key project details. Review the sections below and send them to the team when you're ready.";
      openLeadForm(prefill);
      resetLeadCaptureState();
      return;
    }

    leadCaptureState.currentStepKey = step.key;
    addMessage(step.prompt, "bot", {
      suggestions: step.options
    });
  }

  function startLeadCaptureFlow(question, prefill, options) {
    resetLeadCaptureState({ persist: false });
    const serviceKey = (prefill && serviceKeyFromProjectTypeValue(prefill.projectType)) || inferServiceKey(question);

    leadCaptureState.active = true;
    leadCaptureState.sourceQuestion = question || "";
    leadCaptureState.answers = {
      serviceKey,
      projectType: projectTypeFromServiceKey(serviceKey),
      customerType: detectCustomerType(question),
      name: (prefill && prefill.name) || "",
      phone: (prefill && prefill.phone) || "",
      email: (prefill && prefill.email) || ""
    };

    if (options && options.deferFirstPrompt) {
      const firstStep = getLeadFlowSteps(leadCaptureState.answers, leadCaptureState.sourceQuestion)[0] || null;
      leadCaptureState.currentStepKey = firstStep ? firstStep.key : "";
      persistChatSessionState();
      return;
    }

    askNextLeadQuestion();
  }

  function handleLeadCaptureAnswer(answer) {
    const step = getCurrentLeadStep();
    if (!step) {
      resetLeadCaptureState();
      return;
    }

    if (step.key === "name" && !canUseAsLeadName(answer)) {
      addMessage("I’d be happy to help with that. First, what’s your name?", "bot");
      return;
    }

    if (step.key === "contact" && !canUseAsLeadContact(answer)) {
      addMessage("I can help with that. What’s the best phone number or email for follow-up?", "bot");
      return;
    }

    storeLeadAnswer(step, answer);
    askNextLeadQuestion();
  }

  function shouldInterruptLeadCapture(answer, step) {
    if (!step) {
      return false;
    }

    const trimmed = (answer || "").trim();
    if (!trimmed) {
      return false;
    }

    if (step.key === "contact" && looksLikeLeadInfo(trimmed)) {
      return false;
    }

    const normalized = normalizePhrase(trimmed);
    if (!normalized) {
      return false;
    }

    if (/\?$/.test(trimmed)) {
      return true;
    }

    if (/^(what|how|do|does|can|is|are|which|where|when|why|who|tell me|types? of)\b/i.test(normalized)) {
      return true;
    }

    return false;
  }

  function scrollMessages(targetBubble, mode) {
    if (!targetBubble) {
      messages.scrollTop = messages.scrollHeight;
      return;
    }

    const bubbleTop = targetBubble.offsetTop;
    const bubbleHeight = targetBubble.offsetHeight;
    const containerHeight = messages.clientHeight;
    const padding = 12;

    if (mode === "start") {
      messages.scrollTop = Math.max(0, bubbleTop - padding);
      return;
    }

    if (mode === "smart") {
      if (bubbleHeight >= containerHeight - padding * 2) {
        messages.scrollTop = Math.max(0, bubbleTop - padding);
        return;
      }

      messages.scrollTop = Math.max(0, bubbleTop + bubbleHeight - containerHeight + padding);
      return;
    }

    messages.scrollTop = messages.scrollHeight;
  }

  function createMessageBubble(sender) {
    const bubble = document.createElement("div");
    bubble.className = `chat-message ${sender}`;
    return bubble;
  }

  function appendSources(parent, sourcesList) {
    if (!Array.isArray(sourcesList) || !sourcesList.length) return;

    const sources = createElement("div", "chat-sources");
    const label = createElement("div", "chat-sources-label", "Website pages");
    sources.appendChild(label);

    sourcesList.forEach((source) => {
      const link = createElement("a", "chat-source-link", source.label);
      link.href = source.url;
      sources.appendChild(link);
    });

    parent.appendChild(sources);
  }

  function appendSuggestions(parent, suggestionsList) {
    if (!Array.isArray(suggestionsList) || !suggestionsList.length) return;

    const suggestions = createElement("div", "chat-suggestions");

    suggestionsList.forEach((question) => {
      const suggestion = createElement("button", "chat-suggestion", question);
      suggestion.type = "button";
      suggestion.dataset.question = question;
      suggestions.appendChild(suggestion);
    });

    parent.appendChild(suggestions);
  }

  function appendActions(parent, actionsList) {
    if (!Array.isArray(actionsList) || !actionsList.length) return;

    const actions = createElement("div", "chat-actions");

    actionsList.forEach((action) => {
      const button = createElement("button", "chat-action", action.label);
      button.type = "button";
      button.dataset.action = action.action;
      if (action.materialKey) button.dataset.materialKey = action.materialKey;
      if (action.prefillMessage) button.dataset.prefillMessage = action.prefillMessage;
      if (action.chatSummary) button.dataset.chatSummary = action.chatSummary;
      if (action.projectType) button.dataset.projectType = action.projectType;
      if (action.question) button.dataset.question = action.question;
      if (action.phone) button.dataset.phone = action.phone;
      if (action.email) button.dataset.email = action.email;
      actions.appendChild(button);
    });

    parent.appendChild(actions);
  }

  function addMessage(text, sender, options) {
    const bubble = createMessageBubble(sender);
    const body = createElement("div", "chat-message-body", text);
    bubble.appendChild(body);

    if (options) {
      appendSources(bubble, options.sources);
      appendSuggestions(bubble, options.suggestions);
      appendActions(bubble, options.actions);
    }

    messages.appendChild(bubble);
    if (!isRestoringChatSession) {
      scrollMessages(bubble, sender.indexOf("bot") !== -1 ? "smart" : "bottom");
      persistChatSessionState();
    }
    return bubble;
  }

  function addStatusMessage(text) {
    const bubble = createMessageBubble("bot chat-status");
    bubble.textContent = text;
    messages.appendChild(bubble);
    scrollMessages(bubble, "bottom");
    return bubble;
  }

  function setBusy(isBusy) {
    input.disabled = isBusy;
    submitButton.disabled = isBusy;
  }

  function cancelPendingReply() {
    pendingReplyRequestKey += 1;

    if (pendingReplyTimer) {
      window.clearTimeout(pendingReplyTimer);
      pendingReplyTimer = 0;
    }

    setBusy(false);
  }

  function createHiddenField(name, value) {
    const field = document.createElement("input");
    field.type = "hidden";
    field.name = name;
    field.value = value;
    return field;
  }

  function createLeadField(labelText, field) {
    const wrapper = createElement("div", "chat-form-field");
    const label = createElement("label", "", labelText);
    label.htmlFor = field.id;
    wrapper.appendChild(label);
    wrapper.appendChild(field);
    return wrapper;
  }

  function createLeadInput(config) {
    const inputField = document.createElement("input");
    inputField.type = config.type;
    inputField.name = config.name;
    inputField.id = config.id;
    inputField.required = Boolean(config.required);
    if (config.autocomplete) inputField.autocomplete = config.autocomplete;
    if (config.placeholder) inputField.placeholder = config.placeholder;
    return createLeadField(config.label, inputField);
  }

  function createLeadSelect(config) {
    const select = document.createElement("select");
    select.name = config.name;
    select.id = config.id;

    config.options.forEach((optionConfig) => {
      const option = document.createElement("option");
      option.value = optionConfig.value;
      option.textContent = optionConfig.label;
      select.appendChild(option);
    });

    return createLeadField(config.label, select);
  }

  function createLeadTextarea(config) {
    const textarea = document.createElement("textarea");
    textarea.name = config.name;
    textarea.id = config.id;
    textarea.rows = config.rows || 4;
    textarea.required = Boolean(config.required);
    if (config.placeholder) textarea.placeholder = config.placeholder;
    return createLeadField(config.label, textarea);
  }

  function createLeadFileInput(config) {
    const inputField = document.createElement("input");
    inputField.type = "file";
    inputField.name = config.name;
    inputField.id = config.id;
    if (config.accept) inputField.accept = config.accept;
    if (config.multiple) inputField.multiple = true;

    const wrapper = createLeadField(config.label, inputField);
    if (config.note) {
      wrapper.appendChild(createElement("div", "chat-form-field-note", config.note));
    }

    return wrapper;
  }

  function validateLeadUploadFiles(files) {
    const uploads = Array.isArray(files) ? files : [];
    if (!uploads.length) {
      return { valid: true, message: "" };
    }

    if (uploads.length > LEAD_UPLOAD_MAX_FILES) {
      return {
        valid: false,
        message: `Please upload no more than ${LEAD_UPLOAD_MAX_FILES} files.`
      };
    }

    let totalSize = 0;
    for (let index = 0; index < uploads.length; index += 1) {
      const file = uploads[index];
      const extension = getFileExtension(file && file.name);
      const mimeType = String((file && file.type) || "").toLowerCase();

      if (!LEAD_UPLOAD_ALLOWED_EXTENSIONS.includes(extension)) {
        return {
          valid: false,
          message: "Please upload PDF, JPG, PNG, WEBP, or GIF files only."
        };
      }

      if (file.size > LEAD_UPLOAD_MAX_FILE_SIZE) {
        return {
          valid: false,
          message: `Each upload must be ${formatUploadSize(LEAD_UPLOAD_MAX_FILE_SIZE)} or smaller.`
        };
      }

      totalSize += file.size;
      if (totalSize > LEAD_UPLOAD_MAX_TOTAL_SIZE) {
        return {
          valid: false,
          message: `Combined uploads must stay under ${formatUploadSize(LEAD_UPLOAD_MAX_TOTAL_SIZE)}.`
        };
      }

      if (mimeType && mimeType !== "application/pdf" && mimeType.indexOf("image/") !== 0) {
        return {
          valid: false,
          message: "Please upload PDF or image files only."
        };
      }
    }

    return { valid: true, message: "" };
  }

  function applyLeadFormPrefill(leadForm, prefill) {
    if (!leadForm || !prefill) return;

    const fieldValues = {
      "project-type": prefill.projectType || "",
      city: prefill.city || "",
      "space-type": prefill.spaceType || "",
      "material-interest": prefill.materialInterest || "",
      "build-type": prefill.buildType || "",
      timeline: prefill.timeline || "",
      name: prefill.name || "",
      phone: prefill.phone || "",
      email: prefill.email || "",
      message: prefill.prefillMessage || "",
      "chat-summary": prefill.chatSummary || "",
      "customer-type": prefill.customerType || "",
      measurements: prefill.measurements || "",
      "tile-complete": prefill.tileComplete || "",
      "project-scope": prefill.projectScope || "",
      "plans-ready": prefill.plansReady || "",
      "pricing-or-scheduling": prefill.pricingOrScheduling || "",
      "home-or-commercial": prefill.homeOrCommercial || ""
    };

    Object.keys(fieldValues).forEach((fieldName) => {
      const field = leadForm.querySelector(`[name="${fieldName}"]`);
      if (field && fieldValues[fieldName]) {
        field.value = fieldValues[fieldName];
      }
    });
  }

  function setLeadFormStatus(leadForm, message, isError) {
    const status = leadForm.querySelector(".chat-form-status");
    if (!status) return;

    status.textContent = message || "";
    status.classList.toggle("is-error", Boolean(isError));
    status.classList.toggle("is-success", Boolean(message) && !isError);
  }

  function disableLeadForm(leadForm) {
    leadForm.querySelectorAll("input, select, textarea, button").forEach((field) => {
      field.disabled = true;
    });
  }

  function getLeadFormFocusField(leadForm) {
    if (!leadForm) return null;

    const orderedFields = leadForm.querySelectorAll('select, input:not([type="hidden"]):not([type="file"]), textarea');
    for (let index = 0; index < orderedFields.length; index += 1) {
      const field = orderedFields[index];
      if (!field.disabled && !field.value) {
        return field;
      }
    }

    return leadForm.querySelector('input[name="name"]') || orderedFields[0] || null;
  }

  function focusLeadFormField(field) {
    if (!field || typeof field.focus !== "function") return;

    try {
      field.focus({ preventScroll: true });
    } catch (error) {
      field.focus();
    }
  }

  function getLeadFormScrollAnchor(bubble) {
    if (!bubble) return null;

    const previousBubble = bubble.previousElementSibling;
    if (previousBubble && previousBubble.classList && previousBubble.classList.contains("chat-message")) {
      return previousBubble;
    }

    return bubble;
  }

  function createLeadFormBubble(prefill) {
    const bubble = createMessageBubble("bot chat-form-bubble");
    const intro = createElement(
      "div",
      "chat-message-body",
      (prefill && prefill.introText) || "Share your project details below. This sends the information to our team for follow-up."
    );
    bubble.appendChild(intro);

    const leadForm = createElement("form", "chat-lead-form");
    leadForm.action = "contact.php";
    leadForm.method = "post";
    leadForm.enctype = "multipart/form-data";

    leadForm.appendChild(createHiddenField("website", ""));
    leadForm.appendChild(createHiddenField("response_format", "json"));
    leadForm.appendChild(createHiddenField("source", "Homepage Chatbot"));
    leadForm.appendChild(createHiddenField("chat-summary", (prefill && prefill.chatSummary) || ""));
    leadForm.appendChild(createHiddenField("chat-transcript", buildChatTranscriptText()));
    leadForm.appendChild(createHiddenField("customer-type", (prefill && prefill.customerType) || ""));
    leadForm.appendChild(createHiddenField("measurements", (prefill && prefill.measurements) || ""));
    leadForm.appendChild(createHiddenField("tile-complete", (prefill && prefill.tileComplete) || ""));
    leadForm.appendChild(createHiddenField("project-scope", (prefill && prefill.projectScope) || ""));
    leadForm.appendChild(createHiddenField("plans-ready", (prefill && prefill.plansReady) || ""));
    leadForm.appendChild(createHiddenField("pricing-or-scheduling", (prefill && prefill.pricingOrScheduling) || ""));
    leadForm.appendChild(createHiddenField("home-or-commercial", (prefill && prefill.homeOrCommercial) || ""));

    leadForm.appendChild(createLeadSelect({
      label: "Project type",
      name: "project-type",
      id: "chat-lead-project-type",
      options: [
        { value: "", label: "Select a project type" },
        { value: "countertops", label: "Countertops" },
        { value: "shower-doors", label: "Shower Glass" },
        { value: "mirrors", label: "Custom Glass / Mirrors" },
        { value: "commercial", label: "Commercial" },
        { value: "other", label: "Other" }
      ]
    }));

    leadForm.appendChild(createLeadInput({
      label: "Full name *",
      type: "text",
      name: "name",
      id: "chat-lead-name",
      autocomplete: "name",
      required: true
    }));

    leadForm.appendChild(createLeadInput({
      label: "Phone number",
      type: "tel",
      name: "phone",
      id: "chat-lead-phone",
      autocomplete: "tel"
    }));

    leadForm.appendChild(createLeadInput({
      label: "Email address",
      type: "email",
      name: "email",
      id: "chat-lead-email",
      autocomplete: "email"
    }));

    leadForm.appendChild(createLeadInput({
      label: "City",
      type: "text",
      name: "city",
      id: "chat-lead-city",
      autocomplete: "address-level2"
    }));

    leadForm.appendChild(createLeadInput({
      label: "Space or project area",
      type: "text",
      name: "space-type",
      id: "chat-lead-space-type",
      placeholder: "Kitchen, bathroom, shower, outdoor kitchen, etc."
    }));

    leadForm.appendChild(createLeadInput({
      label: "Material interest",
      type: "text",
      name: "material-interest",
      id: "chat-lead-material-interest",
      placeholder: "Quartz, granite, marble, help deciding, etc."
    }));

    leadForm.appendChild(createLeadSelect({
      label: "New construction or remodel",
      name: "build-type",
      id: "chat-lead-build-type",
      options: [
        { value: "", label: "Select one" },
        { value: "New construction", label: "New construction" },
        { value: "Remodel", label: "Remodel" },
        { value: "Not sure yet", label: "Not sure yet" }
      ]
    }));

    leadForm.appendChild(createLeadInput({
      label: "Timeline",
      type: "text",
      name: "timeline",
      id: "chat-lead-timeline",
      placeholder: "ASAP, next few weeks, just planning, etc."
    }));

    leadForm.appendChild(createLeadTextarea({
      label: "Project details or extra notes",
      name: "message",
      id: "chat-lead-message",
      rows: 4,
      placeholder: "Tell us anything else you'd like the team to know."
    }));

    leadForm.appendChild(createLeadFileInput({
      label: "Plans or images",
      name: "project-files[]",
      id: "chat-lead-files",
      accept: LEAD_UPLOAD_ACCEPT,
      multiple: true,
      note: `Upload up to ${LEAD_UPLOAD_MAX_FILES} files. PDF, JPG, PNG, WEBP, or GIF. ${formatUploadSize(LEAD_UPLOAD_MAX_FILE_SIZE)} each.`
    }));

    const note = createElement(
      "div",
      "chat-form-note",
      "Required fields are name and either a phone number or email address. You can also attach plans or photos before sending."
    );
    leadForm.appendChild(note);

    const status = createElement("div", "chat-form-status");
    leadForm.appendChild(status);

    const actions = createElement("div", "chat-form-actions");
    const submit = createElement("button", "chat-form-submit", "Send to team");
    submit.type = "submit";
    actions.appendChild(submit);
    leadForm.appendChild(actions);

    applyLeadFormPrefill(leadForm, prefill);
    attachLeadFormAutosave(leadForm);

    bubble.appendChild(leadForm);
    return bubble;
  }

  function openLeadForm(prefill, config) {
    const options = config || {};
    const existingForm = messages.querySelector('.chat-lead-form:not([data-completed="true"])');
    if (existingForm) {
      applyLeadFormPrefill(existingForm, prefill);
      attachLeadFormAutosave(existingForm);
      const existingBubble = existingForm.closest(".chat-message");
      const existingIntro = existingBubble ? existingBubble.querySelector(".chat-message-body") : null;
      if (existingIntro && prefill && prefill.introText) {
        existingIntro.textContent = prefill.introText;
      }
      if (options.statusText) {
        setLeadFormStatus(existingForm, options.statusText, options.statusError);
      }
      if (!isRestoringChatSession && options.scroll !== false) {
        scrollMessages(getLeadFormScrollAnchor(existingBubble), "start");
      }
      if (!isRestoringChatSession && options.focus !== false) {
        focusLeadFormField(getLeadFormFocusField(existingForm));
      }
      if (!isRestoringChatSession && options.persist !== false) {
        persistChatSessionState();
      }
      return;
    }

    const bubble = createLeadFormBubble(prefill || {});
    const leadForm = bubble.querySelector(".chat-lead-form");
    if (leadForm && options.statusText) {
      setLeadFormStatus(leadForm, options.statusText, options.statusError);
    }
    messages.appendChild(bubble);
    if (!isRestoringChatSession && options.scroll !== false) {
      scrollMessages(getLeadFormScrollAnchor(bubble), "start");
      window.setTimeout(() => {
        scrollMessages(getLeadFormScrollAnchor(bubble), "start");
      }, 0);
    }

    if (!isRestoringChatSession && options.focus !== false) {
      focusLeadFormField(getLeadFormFocusField(leadForm));
    }
    if (!isRestoringChatSession && options.persist !== false) {
      persistChatSessionState();
    }
  }

  async function submitLeadForm(leadForm) {
    if (!leadForm.checkValidity()) {
      if (typeof leadForm.reportValidity === "function") {
        leadForm.reportValidity();
      }
      return;
    }

    const emailField = leadForm.querySelector('input[name="email"]');
    const phoneField = leadForm.querySelector('input[name="phone"]');
    const messageField = leadForm.querySelector('textarea[name="message"]');
    const chatSummaryField = leadForm.querySelector('input[name="chat-summary"]');
    const chatTranscriptField = leadForm.querySelector('input[name="chat-transcript"]');
    const uploadsField = leadForm.querySelector('input[name="project-files[]"]');
    const emailValue = emailField ? emailField.value.trim() : "";
    const phoneValue = phoneField ? phoneField.value.trim() : "";
    const messageValue = messageField ? messageField.value.trim() : "";
    const chatSummaryValue = chatSummaryField ? chatSummaryField.value.trim() : "";
    const uploadFiles = uploadsField ? Array.from(uploadsField.files || []) : [];
    const structuredFieldNames = [
      "project-type",
      "city",
      "space-type",
      "material-interest",
      "build-type",
      "timeline",
      "measurements",
      "tile-complete",
      "project-scope",
      "plans-ready",
      "pricing-or-scheduling",
      "home-or-commercial"
    ];
    const hasStructuredDetails = structuredFieldNames.some((fieldName) => {
      const field = leadForm.querySelector(`[name="${fieldName}"]`);
      return field && field.value && field.value.trim();
    }) || Boolean(chatSummaryValue) || uploadFiles.length > 0;

    if (!emailValue && !phoneValue) {
      setLeadFormStatus(leadForm, "Please provide either a phone number or email address so we can follow up.", true);
      if (phoneField) phoneField.focus();
      return;
    }

    if (emailValue && !EMAIL_PATTERN.test(emailValue)) {
      setLeadFormStatus(leadForm, "Please provide a valid email address or remove it and leave a phone number instead.", true);
      if (emailField) emailField.focus();
      return;
    }

    if (!messageValue && !hasStructuredDetails) {
      setLeadFormStatus(leadForm, "Please add some project details here or answer a few project questions in the chat first.", true);
      if (messageField) messageField.focus();
      return;
    }

    const uploadValidation = validateLeadUploadFiles(uploadFiles);
    if (!uploadValidation.valid) {
      setLeadFormStatus(leadForm, uploadValidation.message, true);
      if (uploadsField) uploadsField.focus();
      return;
    }

    if (window.location.protocol === "file:") {
      setLeadFormStatus(
        leadForm,
        "Lead capture needs the site to run on a PHP-enabled server. Once uploaded to hosting, this form will email your team.",
        true
      );
      return;
    }

    if (chatTranscriptField) {
      chatTranscriptField.value = buildChatTranscriptText();
    }

    setLeadFormStatus(leadForm, "Sending...", false);
    const submit = leadForm.querySelector('button[type="submit"]');
    submit.disabled = true;

    try {
      const response = await fetch(leadForm.action || "contact.php", {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        },
        body: new FormData(leadForm)
      });

      let result = null;
      try {
        result = await response.json();
      } catch (error) {
        result = null;
      }

      if (!response.ok || !result || !result.success) {
        throw new Error(
          (result && result.message) ||
          "We could not send your information right now. Please call (910) 484-5277 or email info@oliveglassandmarble.com."
        );
      }

      leadForm.dataset.completed = "true";
      disableLeadForm(leadForm);
      submit.textContent = "Sent";
      setLeadFormStatus(leadForm, result.message, false);
      addMessage("Thanks. Your contact details were sent to our team from the chat.", "bot");
    } catch (error) {
      submit.disabled = false;
      setLeadFormStatus(
        leadForm,
        error && error.message
          ? error.message
          : "We could not send your information right now. Please try again.",
        true
      );
    }
  }

  let hasGreeted = false;

  function restoreChatSessionState() {
    const stored = readChatSessionState();
    if (!stored || (stored.sessionId && stored.sessionId !== chatSessionId)) {
      return;
    }

    messages.innerHTML = "";
    isRestoringChatSession = true;

    if (stored.conversationState) {
      resetConversationState();
      Object.assign(conversationState, stored.conversationState);
    }

    if (stored.compareState) {
      compareState.active = Boolean(stored.compareState.active);
      compareState.selectedKeys = Array.isArray(stored.compareState.selectedKeys)
        ? stored.compareState.selectedKeys.slice(0, 4)
        : [];
    }

    if (stored.leadCaptureState) {
      leadCaptureState.active = Boolean(stored.leadCaptureState.active);
      leadCaptureState.sourceQuestion = stored.leadCaptureState.sourceQuestion || "";
      leadCaptureState.currentStepKey = stored.leadCaptureState.currentStepKey || "";
      leadCaptureState.answers = stored.leadCaptureState.answers && typeof stored.leadCaptureState.answers === "object"
        ? { ...stored.leadCaptureState.answers }
        : {};
    }

    (stored.transcript || []).forEach((item) => {
      if (!item || !item.type) {
        return;
      }

      if (item.type === "message") {
        addMessage(item.text || "", item.sender === "user" ? "user" : "bot", {
          sources: Array.isArray(item.sources) ? item.sources : [],
          suggestions: Array.isArray(item.suggestions) ? item.suggestions : [],
          actions: Array.isArray(item.actions) ? item.actions : []
        });
        return;
      }

      if (item.type === "lead_form" && item.prefill) {
        openLeadForm({
          ...item.prefill,
          introText: item.introText || ""
        }, {
          focus: false,
          scroll: false,
          persist: false,
          statusText: item.statusText || "",
          statusError: Boolean(item.statusError)
        });
      }
    });

    isRestoringChatSession = false;
    hasGreeted = Boolean(stored.hasGreeted) || Boolean(messages.children.length);

    if (stored.panelOpen) {
      openChat();
    } else {
      updateChatViewportState();
    }

    if (messages.lastElementChild) {
      scrollMessages(messages.lastElementChild, "bottom");
    }
  }

  function shouldAutoFocusChatInput() {
    if (typeof window === "undefined") {
      return true;
    }

    if (window.innerWidth <= 768) {
      return false;
    }

    if (typeof window.matchMedia === "function") {
      return !window.matchMedia("(pointer: coarse)").matches;
    }

    return true;
  }

  function focusChatInput() {
    if (!shouldAutoFocusChatInput()) {
      return;
    }

    try {
      input.focus({ preventScroll: true });
    } catch (error) {
      input.focus();
    }
  }

  function openChat() {
    panel.classList.add("is-open");
    widget.classList.add("is-open");
    toggle.setAttribute("aria-expanded", "true");
    updateChatViewportState();

    if (!hasGreeted) {
      addMessage(
        WELCOME_MESSAGE,
        "bot",
        {
          suggestions: WELCOME_QUICK_REPLIES
        }
      );
      hasGreeted = true;
    }

    focusChatInput();
    queuePreserveMobileChatContext(160);
    persistChatSessionState();
  }

  function closeChat() {
    flushPartialLeadCapture("lead_form_close");

    panel.classList.remove("is-open");
    widget.classList.remove("is-open");
    toggle.setAttribute("aria-expanded", "false");
    resetMaterialCompareState();
    updateChatViewportState();
    persistChatSessionState();
  }

  function resetChatExperience(options) {
    const keepOpen = !options || options.keepOpen !== false;

    cancelPendingReply();
    window.clearTimeout(partialLeadSaveTimer);
    window.clearTimeout(preserveMobileChatTimer);

    resetMaterialCompareState({ persist: false });
    resetLeadCaptureState({ persist: false });
    resetConversationState();

    messages.innerHTML = "";
    input.value = "";
    hasGreeted = false;
    lastPartialLeadSignature = "";
    isRestoringChatSession = false;
    clearPersistedChatSessionState();

    chatSessionId = createChatSessionId();
    storeChatSessionId(chatSessionId);

    if (keepOpen) {
      openChat();
      return;
    }

    panel.classList.remove("is-open");
    widget.classList.remove("is-open");
    toggle.setAttribute("aria-expanded", "false");
    updateChatViewportState();
  }

  function isResetChatRequest(question) {
    return RESET_CHAT_PATTERN.test(normalizePhrase(question));
  }

  function logChatGap(question, replyMeta, previousContext) {
    if (!replyMeta || !replyMeta.logGap || window.location.protocol === "file:") {
      return;
    }

    const payload = {
      question,
      reason: replyMeta.gapReason || "low_confidence",
      confidence: replyMeta.confidence || "low",
      intent: replyMeta.intent || "",
      service_key: replyMeta.serviceKey || "",
      customer_type: replyMeta.customerType || "",
      space_type: replyMeta.spaceType || "",
      material_priority: replyMeta.materialPriority || "",
      material_keys: Array.isArray(replyMeta.materialKeys) ? replyMeta.materialKeys : [],
      previous_question: (previousContext && previousContext.lastQuestion) || "",
      previous_intent: (previousContext && previousContext.lastIntent) || "",
      timestamp: new Date().toISOString()
    };

    try {
      const body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        const blob = new Blob([body], { type: "application/json" });
        navigator.sendBeacon("chat-log.php", blob);
        return;
      }

      fetch("chat-log.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body,
        keepalive: true
      }).catch(() => {});
    } catch (error) {
      // Logging should never interrupt the chat experience.
    }
  }

  function flushPartialLeadCapture(captureSource) {
    const draftLeadForm = messages.querySelector('.chat-lead-form:not([data-completed="true"])');
    if (draftLeadForm) {
      sendPartialLeadCapture(
        buildPartialLeadPayloadFromForm(draftLeadForm, captureSource || "lead_form_flush"),
        { immediate: true }
      );
      return;
    }

    if (leadCaptureState && leadCaptureState.answers && leadCaptureState.answers.contact) {
      savePartialLeadFromAnswers(captureSource || "lead_flow_flush", { immediate: true });
    }
  }

  function handleQuestion(question, displayText) {
    if (input.disabled) return;
    if (isResetChatRequest(question)) {
      resetChatExperience({ keepOpen: true });
      return;
    }

    const shownQuestion = displayText || question;
    addMessage(shownQuestion, "user");

    let interruptedLeadStep = null;
    if (leadCaptureState.active) {
      const currentLeadStep = getCurrentLeadStep();
      if (!shouldInterruptLeadCapture(question, currentLeadStep)) {
        handleLeadCaptureAnswer(question);
        focusChatInput();
        return;
      }

      interruptedLeadStep = currentLeadStep;
    }

    const resolvedQuestion = resolveQuickReplyPrompt(question);
    const previousContext = {
      lastQuestion: conversationState.lastQuestion,
      lastIntent: conversationState.lastIntent
    };
    if (shouldStartMaterialCompareFlow(resolvedQuestion)) {
      startMaterialCompareFlow(getCountertopMaterialTerms(resolvedQuestion).map((term) => term.key));
      focusChatInput();
      return;
    }

    if (compareState.active) {
      resetMaterialCompareState();
    }

    if (looksLikeLeadInfo(resolvedQuestion)) {
      const contactDetails = parseContactDetails(resolvedQuestion);
      sendPartialLeadCapture(buildPartialLeadPayload({
        captureSource: "chat_direct_contact",
        question: resolvedQuestion,
        name: "",
        email: contactDetails.email,
        phone: contactDetails.phone,
        projectType: detectProjectType(conversationState.lastQuestion || resolvedQuestion),
        city: "",
        spaceType: conversationState.spaceType || "",
        materialInterest: conversationState.lastMaterialKeys.join(", "),
        buildType: "",
        timeline: "",
        chatSummary: `Chat inquiry: ${resolvedQuestion}`,
        customerType: conversationState.customerType || "",
        measurements: "",
        tileComplete: "",
        projectScope: "",
        plansReady: "",
        pricingOrScheduling: "",
        homeOrCommercial: ""
      }), { immediate: true });

      addMessage(
        "I can pass that to the team. I can either walk through a few project questions here or let you send the form right away.",
        "bot",
        { actions: buildLeadActions(resolvedQuestion) }
      );
      focusChatInput();
      return;
    }

    setBusy(true);
    const status = addStatusMessage("Checking the website and business FAQ...");
    const requestKey = ++pendingReplyRequestKey;

    pendingReplyTimer = window.setTimeout(() => {
      pendingReplyTimer = 0;
      if (requestKey !== pendingReplyRequestKey) {
        return;
      }

      status.remove();
      const reply = buildReply(resolvedQuestion);
      addMessage(reply.text, "bot", {
        sources: reply.sources,
        suggestions: reply.suggestions,
        actions: reply.actions
      });

      if (interruptedLeadStep && leadCaptureState.active && !reply.startLeadFlow) {
        addMessage("I can answer that while we keep your project details going.", "bot");
        addMessage(interruptedLeadStep.prompt, "bot", {
          suggestions: interruptedLeadStep.options
        });
      }

      logChatGap(resolvedQuestion, reply.meta, previousContext);

      if (reply.startLeadFlow) {
        if (interruptedLeadStep && leadCaptureState.active) {
          addMessage("I can keep your project details going here.", "bot");
          addMessage(interruptedLeadStep.prompt, "bot", {
            suggestions: interruptedLeadStep.options
          });
        } else {
          startLeadCaptureFlow(resolvedQuestion, {
            projectType: reply.leadProjectType || detectProjectType(resolvedQuestion)
          }, {
            deferFirstPrompt: true
          });
        }
      }

      setBusy(false);
      focusChatInput();
    }, 220);
  }

  toggle.addEventListener("click", () => {
    if (panel.classList.contains("is-open")) {
      closeChat();
    } else {
      openChat();
    }
  });

  closeBtn.addEventListener("click", closeChat);
  resetBtn.addEventListener("click", () => {
    resetChatExperience({ keepOpen: true });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && panel.classList.contains("is-open")) {
      closeChat();
      toggle.focus();
    }
  });

  messages.addEventListener("click", (event) => {
    const suggestion = event.target.closest(".chat-suggestion");
    if (suggestion) {
      const question = suggestion.dataset.question;
      if (question) handleQuestion(question);
      return;
    }

    const action = event.target.closest(".chat-action");
    if (!action) return;

    if (action.dataset.action === "start-material-compare") {
      addMessage(action.textContent.trim(), "user");
      startMaterialCompareFlow();
      return;
    }

    if (action.dataset.action === "select-material-compare") {
      handleMaterialCompareSelection(action.dataset.materialKey || "");
      return;
    }

    if (action.dataset.action === "start-lead-flow") {
      startLeadCaptureFlow(action.dataset.question || conversationState.lastQuestion || "I want a quote", {
        projectType: action.dataset.projectType || "",
        phone: action.dataset.phone || "",
        email: action.dataset.email || ""
      });
      return;
    }

    if (action.dataset.action === "open-lead-form") {
      openLeadForm({
        prefillMessage: action.dataset.prefillMessage || "",
        chatSummary: action.dataset.chatSummary || "",
        projectType: action.dataset.projectType || "",
        phone: action.dataset.phone || "",
        email: action.dataset.email || ""
      });
    }
  });

  messages.addEventListener("submit", (event) => {
    const leadForm = event.target.closest(".chat-lead-form");
    if (!leadForm) return;
    event.preventDefault();
    submitLeadForm(leadForm);
  });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const question = input.value.trim();
    if (!question || input.disabled) return;
    input.value = "";
    handleQuestion(question);
  });

  widget.addEventListener("focusin", () => {
    queuePreserveMobileChatContext(180);
  });

  window.addEventListener("resize", () => {
    updateChatViewportState();
    queuePreserveMobileChatContext(60);
  }, { passive: true });

  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", () => {
      updateChatViewportState();
      queuePreserveMobileChatContext(60);
    });

    window.visualViewport.addEventListener("scroll", () => {
      updateChatViewportState();
      queuePreserveMobileChatContext(60);
    });
  }

  restoreChatSessionState();

  window.addEventListener("pagehide", () => {
    flushPartialLeadCapture("pagehide_partial_lead");
    persistChatSessionState();
  });
})();
