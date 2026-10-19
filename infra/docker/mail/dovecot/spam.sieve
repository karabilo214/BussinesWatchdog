require ["fileinto"];

if anyof (header :is "X-Spam" "Yes", header :contains "X-Spam-Status" "Yes") {
  fileinto "Junk";
  stop;
}
