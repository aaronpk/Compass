Array.prototype.clean = function(deleteValue) {
  for (var i = 0; i < this.length; i++) {
    if (this[i] == deleteValue) {         
      this.splice(i, 1);
      i--;
    }
  }
  return this;
};

/*
  Array.findRanges
  
  Turns this:
  
  ["a","a","a","b","b","c","c","c","c","c","a","a","c"]
  
  into this:
  
  {
    "a":[
      {
        "from":0,
        "to":2
      },
      {
        "from":10,
        "to":11
      }
    ],
    "b":[
      {
        "from":3,
        "to":4
      }
    ],
    "c":[
      {
        "from":5,
        "to":9
      },
      {
        "from":12,
        "to":12
      }
    ]
  }

*/

Array.prototype.findRanges = function() {
  var buckets = {};
  for(var i = 0; i < this.length; i++) {
    if(!(this[i] in buckets)) {
      buckets[this[i]] = [{
        from: i,
        to: i
      }]
    } else {
      var last = buckets[this[i]][ buckets[this[i]].length-1 ];
      if(i == last.to + 1) {
        last.to = i;
      } else {
        buckets[this[i]].push({
          from: i,
          to: i
        })
      }
    }
  }
  return buckets;
};

Object.values = function(obj){ return Object.keys(obj).map(function(key){return obj[key]}) };
