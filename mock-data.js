/* mock-data.js — sample tenant so the pages render before the API exists.
 *
 * DELETE THIS FILE when api/*.php is wired. Every page that loads it also
 * shows the amber "sample data" bar, so nothing here can quietly end up
 * looking like production.
 *
 * Contact values are deliberately stored ALREADY MASKED. In the real
 * product the browser never receives an unmasked phone or email until
 * the rep spends quota on a reveal, and even then it arrives as a
 * watermarked image rather than text (requirements 7.1 and 7.3). Keeping
 * the mock the same shape stops anyone building a UI that assumes the
 * plain value is sitting there in the payload.
 */
window.MOCK = (function () {
  'use strict';

  var now = Date.now();
  function hoursAgo(h) { return new Date(now - h * 3600000).toISOString(); }
  function daysAgo(d)  { return new Date(now - d * 86400000).toISOString(); }

  var users = [
    { id: 'u-101', name: 'Priya Sharma',  role: 'rep',   email: 'priya@moveneticsdigital.com',   quota: 40, usedToday: 31, assigned: 214, status: 'active',    lastSeen: hoursAgo(0.3) },
    { id: 'u-102', name: 'Rahul Mehta',   role: 'rep',   email: 'rahul@moveneticsdigital.com',   quota: 40, usedToday: 38, assigned: 198, status: 'active',    lastSeen: hoursAgo(1) },
    { id: 'u-103', name: 'Aisha Khan',    role: 'rep',   email: 'aisha@moveneticsdigital.com',   quota: 25, usedToday: 25, assigned: 156, status: 'flagged',   lastSeen: hoursAgo(0.1) },
    { id: 'u-104', name: 'Vikram Nair',   role: 'rep',   email: 'vikram@moveneticsdigital.com',  quota: 40, usedToday: 12, assigned: 173, status: 'active',    lastSeen: hoursAgo(5) },
    { id: 'u-105', name: 'Sneha Patil',   role: 'rep',   email: 'sneha@moveneticsdigital.com',   quota: 30, usedToday: 0,  assigned: 88,  status: 'suspended', lastSeen: daysAgo(6) },
    { id: 'u-001', name: 'Arjun Desai',   role: 'admin', email: 'arjun@moveneticsdigital.com',   quota: 0,  usedToday: 0,  assigned: 0,   status: 'active',    lastSeen: hoursAgo(0.05) }
  ];

  var statuses = ['new', 'working', 'won', 'lost'];
  var cities    = ['Mumbai', 'Pune', 'Bengaluru', 'Delhi', 'Hyderabad', 'Ahmedabad', 'Chennai'];
  var industries= ['Manufacturing', 'Logistics', 'Retail', 'IT Services', 'Healthcare', 'Real Estate'];
  var sources   = ['IndiaMART', 'Purchased list — Q2', 'Website form', 'JustDial', 'Trade show', 'LinkedIn export'];
  var firstNames= ['Amit','Neha','Rohan','Kavya','Sanjay','Divya','Karan','Meera','Anil','Pooja','Suresh','Ritu','Manish','Anjali','Deepak','Farah','Nikhil','Shreya'];
  var lastNames = ['Gupta','Reddy','Iyer','Joshi','Bose','Malhotra','Rao','Chopra','Kulkarni','Banerjee','Shetty','Pillai'];
  var companies = ['Vertex Industries','Blue Ridge Logistics','Nova Retail','Corex Systems','Halcyon Health','Meridian Estates','Ironwood Traders','Skyline Packaging','Quanta Labs','Trident Motors'];
  var titles    = ['Procurement Head','Founder','Operations Manager','CTO','Purchase Executive','Director','Plant Manager','VP Sales'];

  /* Deterministic pseudo-random. A fixed seed means the sample tenant
   * looks identical on every reload, so a screenshot taken today still
   * matches the page tomorrow. */
  var seed = 20260819;
  function rnd() {
    seed = (seed * 1103515245 + 12345) % 2147483648;
    return seed / 2147483648;
  }
  function pick(arr) { return arr[Math.floor(rnd() * arr.length)]; }
  function int(min, max) { return Math.floor(rnd() * (max - min + 1)) + min; }

  var leads = [];
  for (var i = 0; i < 240; i++) {
    var first = pick(firstNames);
    var last  = pick(lastNames);
    var owner = rnd() < 0.12 ? null : pick(users.filter(function (u) { return u.role === 'rep'; }));
    var status = rnd() < 0.42 ? 'new' : pick(statuses);

    leads.push({
      id: 'L-' + String(4200 + i),
      name: first + ' ' + last,
      company: pick(companies),
      designation: pick(titles),

      // Masked on arrival — see the file header.
      phoneMasked: '+91 ' + int(70, 99) + '***' + int(10, 99) + int(10, 99),
      emailMasked: (first[0] + last[0]).toLowerCase() + '****@' +
                   pick(['gmail.com', 'outlook.com', 'company.co.in']),

      city: pick(cities),
      industry: pick(industries),
      companySize: pick(['1–10', '11–50', '51–200', '201–500', '500+']),
      source: pick(sources),
      sourceCost: int(18, 140),
      status: status,
      ownerId: owner ? owner.id : null,
      ownerName: owner ? owner.name : null,
      acquiredDate: daysAgo(int(1, 90)),
      lastContacted: rnd() < 0.4 ? null : hoursAgo(int(2, 400)),
      notes: rnd() < 0.3 ? 'Asked for a callback next week.' : '',

      /* Honeytokens — requirements 7.3. Seeded per rep, never shown as
       * such in the rep UI. Flagged here so the admin views can mark
       * them and nobody removes them thinking they are bad data. */
      honeytoken: rnd() < 0.012
    });
  }

  var sourceRoi = [
    { source: 'IndiaMART',           leads: 68, cost: 5400, won: 9,  revenue: 412000 },
    { source: 'Purchased list — Q2', leads: 92, cost: 11800, won: 6, revenue: 288000 },
    { source: 'Website form',        leads: 31, cost: 0,     won: 8, revenue: 356000 },
    { source: 'JustDial',            leads: 24, cost: 1900,  won: 2, revenue: 74000 },
    { source: 'Trade show',          leads: 15, cost: 8200,  won: 4, revenue: 198000 },
    { source: 'LinkedIn export',     leads: 10, cost: 1200,  won: 1, revenue: 41000 }
  ];

  var anomalies = [
    { level: 'red',   user: 'Aisha Khan',   text: 'Hit the daily reveal quota in 11 minutes — 25 of 25 used.', at: hoursAgo(0.4) },
    { level: 'red',   user: 'Aisha Khan',   text: 'Signed in from an unrecognised device fingerprint (a3f19c22).', at: hoursAgo(2) },
    { level: 'amber', user: 'Rahul Mehta',  text: '38 reveals today against 4 status updates — viewing far more than working.', at: hoursAgo(3) },
    { level: 'amber', user: 'Priya Sharma', text: 'Copy blocked 14 times on My Leads.', at: hoursAgo(5) },
    { level: 'grey',  user: 'Vikram Nair',  text: 'Signed in at 03:12 local time.', at: hoursAgo(9) }
  ];

  var audit = [];
  var actions = [
    ['reveal',    'Revealed phone on'],
    ['view',      'Opened lead'],
    ['status',    'Changed status of'],
    ['assign',    'Assigned lead'],
    ['login',     'Signed in'],
    ['blocked',   'Copy blocked on'],
    ['import',    'Imported 1,240 leads from'],
    ['email',     'Sent relay email for']
  ];
  for (var j = 0; j < 60; j++) {
    var act = pick(actions);
    var who = pick(users);
    audit.push({
      id: 'a-' + (9000 + j),
      action: act[0],
      text: act[1],
      subject: act[0] === 'import' ? 'q3-purchased-list.xlsx'
             : act[0] === 'login'  ? ''
             : 'L-' + String(4200 + int(0, 239)),
      actor: who.name,
      actorId: who.id,
      ip: '103.' + int(20, 99) + '.' + int(1, 250) + '.' + int(1, 250),
      device: pick(['a3f19c22', '7be40d15', 'c0912fab']),
      at: hoursAgo(j * 0.7 + rnd())
    });
  }

  /* Fourteen days of activity for the dashboard trend. */
  var trend = [];
  for (var d = 13; d >= 0; d--) {
    trend.push({
      date: new Date(now - d * 86400000).toISOString().slice(0, 10),
      reveals: int(60, 190),
      contacted: int(30, 120),
      won: int(0, 7)
    });
  }

  /* Company laptops — the mTLS device register.
   * Serials are stored the way api/device.php normalises them: uppercase
   * hex, no colons, no leading zeros. */
  var devices = [
    { id: 1, code: 'LAPTOP-001', serial: '8A91F23B', subject: 'CN=LAPTOP-001',
      employee: 'Priya Sharma', employeeId: 'u-101', status: 'active',
      issuedAt: daysAgo(120), expiresAt: new Date(now + 245 * 86400000).toISOString(),
      lastSeenAt: hoursAgo(0.3), lastSeenIp: '103.42.18.7' },

    { id: 2, code: 'LAPTOP-002', serial: '91BC72DA', subject: 'CN=LAPTOP-002',
      employee: 'Rahul Mehta', employeeId: 'u-102', status: 'active',
      issuedAt: daysAgo(118), expiresAt: new Date(now + 247 * 86400000).toISOString(),
      lastSeenAt: hoursAgo(1), lastSeenIp: '103.42.18.9' },

    // Expiring inside 60 days — the page flags this before it locks
    // someone out at the TLS layer, where Datafort cannot explain itself.
    { id: 3, code: 'LAPTOP-003', serial: '72AB91CD', subject: 'CN=LAPTOP-003',
      employee: 'Aisha Khan', employeeId: 'u-103', status: 'active',
      issuedAt: daysAgo(320), expiresAt: new Date(now + 41 * 86400000).toISOString(),
      lastSeenAt: hoursAgo(0.1), lastSeenIp: '103.42.19.114' },

    { id: 4, code: 'LAPTOP-004', serial: 'C4D80E17', subject: 'CN=LAPTOP-004',
      employee: 'Vikram Nair', employeeId: 'u-104', status: 'active',
      issuedAt: daysAgo(90), expiresAt: new Date(now + 275 * 86400000).toISOString(),
      lastSeenAt: hoursAgo(5), lastSeenIp: '103.42.18.22' },

    { id: 5, code: 'LAPTOP-005', serial: 'F13A9B04', subject: 'CN=LAPTOP-005',
      employee: 'Sneha Patil', employeeId: 'u-105', status: 'revoked',
      issuedAt: daysAgo(200), expiresAt: new Date(now + 165 * 86400000).toISOString(),
      revokedAt: daysAgo(6), lastSeenAt: daysAgo(6), lastSeenIp: '103.42.20.88' },

    // Registered but never activated — cannot sign in yet.
    { id: 6, code: 'LAPTOP-006', serial: '2E7F40A9', subject: 'CN=LAPTOP-006',
      employee: null, employeeId: null, status: 'pending',
      issuedAt: hoursAgo(3), expiresAt: new Date(now + 365 * 86400000).toISOString(),
      lastSeenAt: null },

    { id: 7, code: 'LAPTOP-ADMIN', serial: 'A05C1D6E', subject: 'CN=LAPTOP-ADMIN',
      employee: 'Arjun Desai', employeeId: 'u-001', status: 'active',
      issuedAt: daysAgo(140), expiresAt: new Date(now + 225 * 86400000).toISOString(),
      lastSeenAt: hoursAgo(0.05), lastSeenIp: '103.42.18.2' }
  ];

  var deviceDenials = [
    { device_code: null, certificate_serial: null, reason: 'no_certificate',
      ip: '49.36.180.22', at: hoursAgo(1.2) },
    { device_code: 'LAPTOP-005', certificate_serial: 'F13A9B04', reason: 'device_revoked',
      ip: '103.42.20.88', at: hoursAgo(4) },
    { device_code: 'LAPTOP-006', certificate_serial: '2E7F40A9', reason: 'device_pending',
      ip: '103.42.18.31', at: hoursAgo(2.5) },
    { device_code: null, certificate_serial: 'BB9021FE', reason: 'unknown_serial',
      ip: '103.42.18.44', at: hoursAgo(20) },
    { device_code: null, certificate_serial: null, reason: 'no_certificate',
      ip: '157.44.9.201', at: hoursAgo(31) }
  ];

  return {
    session: { id: 'u-001', name: 'Arjun Desai', role: 'admin', tenant: 'Movenetics Digital' },
    repSession: { id: 'u-101', name: 'Priya Sharma', role: 'rep', tenant: 'Movenetics Digital' },

    devices: devices,
    deviceDenials: deviceDenials,
    // 'log' is the mode a real rollout sits in for weeks — see devices.js.
    deviceMode: 'log',
    thisDeviceSerial: 'A05C1D6E',
    users: users,
    leads: leads,
    sourceRoi: sourceRoi,
    anomalies: anomalies,
    audit: audit,
    trend: trend,

    byStatus: function () {
      var c = { new: 0, working: 0, won: 0, lost: 0 };
      leads.forEach(function (l) { c[l.status]++; });
      return c;
    },

    forUser: function (userId) {
      return leads.filter(function (l) { return l.ownerId === userId; });
    },

    unassigned: function () {
      return leads.filter(function (l) { return !l.ownerId; });
    }
  };
})();
