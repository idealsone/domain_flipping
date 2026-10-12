(() => {
  'use strict';
  const data = {
    'Andhra Pradesh': ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Tirupati', 'Nellore', 'Kurnool'],
    'Arunachal Pradesh': ['Itanagar', 'Naharlagun', 'Tawang'],
    Assam: ['Guwahati', 'Dibrugarh', 'Silchar', 'Jorhat'],
    Bihar: ['Patna', 'Gaya', 'Muzaffarpur', 'Bhagalpur'],
    Chhattisgarh: ['Raipur', 'Bhilai', 'Bilaspur', 'Durg'],
    Goa: ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa'],
    Gujarat: ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar'],
    Haryana: ['Gurugram', 'Faridabad', 'Panipat', 'Ambala', 'Hisar'],
    'Himachal Pradesh': ['Shimla', 'Dharamshala', 'Mandi', 'Solan'],
    Jharkhand: ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro'],
    Karnataka: ['Bengaluru', 'Mysuru', 'Mangaluru', 'Hubballi', 'Belagavi'],
    Kerala: ['Thiruvananthapuram', 'Kochi', 'Kozhikode', 'Thrissur', 'Kannur'],
    'Madhya Pradesh': ['Bhopal', 'Indore', 'Gwalior', 'Jabalpur', 'Ujjain'],
    Maharashtra: ['Mumbai', 'Pune', 'Nagpur', 'Nashik', 'Thane', 'Navi Mumbai', 'Aurangabad'],
    Manipur: ['Imphal'],
    Meghalaya: ['Shillong'],
    Mizoram: ['Aizawl'],
    Nagaland: ['Kohima', 'Dimapur'],
    Odisha: ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Puri'],
    Punjab: ['Chandigarh', 'Ludhiana', 'Amritsar', 'Jalandhar', 'Mohali'],
    Rajasthan: ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota', 'Ajmer'],
    Sikkim: ['Gangtok'],
    'Tamil Nadu': ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem'],
    Telangana: ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar'],
    Tripura: ['Agartala'],
    'Uttar Pradesh': ['Lucknow', 'Noida', 'Ghaziabad', 'Kanpur', 'Prayagraj', 'Varanasi', 'Agra'],
    Uttarakhand: ['Dehradun', 'Haridwar', 'Nainital', 'Haldwani'],
    'West Bengal': ['Kolkata', 'Howrah', 'Siliguri', 'Durgapur', 'Asansol'],
    Delhi: ['New Delhi', 'Delhi', 'Dwarka', 'Rohini', 'Saket'],
    Chandigarh: ['Chandigarh'],
    Puducherry: ['Puducherry', 'Karaikal'],
    'Jammu and Kashmir': ['Srinagar', 'Jammu'],
    Ladakh: ['Leh', 'Kargil'],
    'Andaman and Nicobar Islands': ['Port Blair'],
    'Dadra and Nagar Haveli and Daman and Diu': ['Daman', 'Silvassa'],
    Lakshadweep: ['Kavaratti']
  };
  function fill(select, items, current, first) {
    if (!select) return;
    const seen = new Set();
    const opts = ['<option value="">' + first + '</option>'];
    items.forEach(item => {
      if (!item || seen.has(item)) return;
      seen.add(item);
      opts.push('<option' + (item === current ? ' selected' : '') + '>' + item.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '</option>');
    });
    if (current && !seen.has(current)) {
      opts.push('<option selected>' + String(current).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '</option>');
    }
    opts.push('<option value="Other"' + (current === 'Other' ? ' selected' : '') + '>Other / not listed</option>');
    select.innerHTML = opts.join('');
  }
  function bind(stateEl, cityEl, state, city) {
    if (!stateEl || !cityEl) return;
    fill(stateEl, Object.keys(data), state || '', 'Select state');
    fill(cityEl, data[state] || [], city || '', 'Select city');
    stateEl.addEventListener('change', () => {
      fill(cityEl, data[stateEl.value] || [], '', 'Select city');
      cityEl.dispatchEvent(new Event('input', {bubbles: true}));
    });
  }
  window.CVTaraPlaces = {data, bind, states: () => Object.keys(data), cities: s => data[s] || []};
})();
