function strrev(s) {
  if (!s) return '';
  var a = '';
  for (i = s.length - 1; i >= 0; i--) {
    a += s.charAt(i)
  }
  return a
};

function flag() {
  var a = '';
  var b = "7b";
  b += "46";
  b += "4c";
  b += "47";
  b += "3a";
  b += "41";
  b += "6c";
  b += "6c";
  b += "53";
  b += "65";
  b += "74";
  b += "2e";
  b += "4d";
  b += "31";
  b += "73";
  b += "73";
  b += "31";
  b += "4f";
  b += "6e";
  b += "53";
  b += "74";
  b += "61";
  b += "72";
  b += "74";
  b += "33";
  b += "64";
  b += "7d";
  for (var i = 0; i < b.length; i += 2) {
    a += String.fromCharCode(parseInt(b.substr(i, 2), 16))
  }
  return a
};

function magic() {
  var a = new Array();
  a[0] = '0';
  a[1] = 'b';
  a[2] = 'f';
  a[3] = 'u';
  a[4] = '5';
  a[5] = 'c';
  a[6] = '4';
  a[7] = '7';
  a[8] = '1';
  a[9] = '0';
  a[10] = 'n';
  a[11] = '_';
  a[12] = '5';
  a[13] = 'u';
  a[14] = 'x';
  a[15] = '5';
  var b = '';
  var c = document.getElementById("pw").value;
  c = strrev(c);
  if (c == a.join("")) {
    b = "Congratulation! The flag is: " + flag()
  } else {
    b = "Try again :("
  }
  document.getElementById("result").innerHTML = b
};