from parser import *

class HTMLParser(object):
    """Main parser class"""

    def __init__(self, strict = False):
        #Raise an exception on the first error encountered
        self.strict = strict


    def parse(self, stream, innerHTML=False):
        """Stream should be a stream of unicode bytes. Character encoding
        issues have not yet been dealt with."""

        #We don't actually support inner HTML yet but this should allow 
        #assertations
        self.innerHTML = innerHTML

        self.tokenizer = tokenizer.HTMLTokenizer(self)
        self.tokenizer.tokenize(stream)

    def processDoctype(self, name, error):
        print "DOCTYPE:", name, error

    def processStartTag(self, name, attributes):
        print "StartTag:", name, attributes

    def processEndTag(self, name, attributes):
        print "EndTag:", name, attributes

    def processComment(self, data):
        print "Comment:", data

    def processCharacter(self, data):
        print "Character:", data
        

    def processEOF(self):
        print "EOF"

    def parseError(self):
        print "Parse Error", self.tokenizer.state

    def atheistParseError(self):
        """This error is not an error"""
        print "Atheist Parse Error"